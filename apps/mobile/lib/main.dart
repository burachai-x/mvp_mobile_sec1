import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'api_client.dart';
import 'api_config.dart';
import 'device_key.dart';
import 'enrollment.dart';
import 'update_checker.dart';
import 'update_manifest.dart';
import 'update_state.dart';

void main() {
  runApp(const DriverApp());
}

class DriverApp extends StatelessWidget {
  const DriverApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Driver',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF1B5E20)),
        useMaterial3: true,
      ),
      home: const EnrollScreen(),
    );
  }
}

enum _Step { loading, needsEnrollment, enrolling, needsPin, active }

class EnrollScreen extends StatefulWidget {
  const EnrollScreen({super.key});

  @override
  State<EnrollScreen> createState() => _EnrollScreenState();
}

class _EnrollScreenState extends State<EnrollScreen> {
  final _api = ApiClient(
    ApiConfig.baseUrl,
    appSignature: ApiConfig.appSignature,
    appVersion: ApiConfig.appVersion,
    pins: ApiConfig.certificatePins(),
    securityContext: ApiConfig.securityContext(),
  );

  _Step _step = _Step.loading;
  EnrollmentState? _state;
  EnrollmentResult? _enrollment;
  String? _error;
  Map<String, bool> _integrity = const {};
  final _log = <String>[];

  @override
  void initState() {
    super.initState();
    _restore();
  }

  /// Shown on screen rather than only in logcat: the point of this build is
  /// watching the hardware path succeed or fail on a real handset.
  void _note(String line) => setState(() => _log.add(line));

  Future<void> _restore() async {
    final state = await EnrollmentState.load();
    final hasKey = await DeviceKey.exists();

    // Shown to whoever is holding the phone rather than only sent to the
    // server: a check nobody ever sees the result of is a check that has
    // stopped working without anyone noticing.
    final integrity = await DeviceKey.integritySignals();

    final updates = await UpdateState.load();

    // Applied from stored state, which is a local read and settles before the
    // first request can happen. The last verified manifest is what governs
    // pinning until a newer one is checked (§11.5).
    _api.pins.killSwitched = !updates.pinningEnabled;

    // Printed because the kill switch is otherwise invisible: a fleet with
    // pinning silently off looks exactly like one with it on.
    _note('Certificate pinning: ${_api.pins.isEnabled ? "enforced" : "off"}');

    setState(() {
      _state = state;
      _integrity = integrity;
      _step = (state.deviceId != null && hasKey) ? _Step.active : _Step.needsEnrollment;
    });

    // Deliberately not awaited. Blocking the first frame on a network call left
    // the phone on a black screen for six seconds, and would have waited the
    // full timeout whenever the update host was unreachable — a driver cannot
    // work while the app decides whether a newer version exists.
    unawaited(_checkForUpdates(updates));
  }

  /// Never silent on failure: a manifest that does not verify is either a
  /// mistake in releasing or someone trying to install software on a driver's
  /// phone, and both need to be seen (§11.3).
  Future<void> _checkForUpdates(UpdateState state) async {
    final verifier = ApiConfig.manifestVerifier();

    if (verifier == null) return;

    try {
      final available = await UpdateChecker(
        manifestUrl: ApiConfig.manifestUrl,
        verifier: verifier,
        installedVersionCode: int.parse(ApiConfig.appVersion),
      ).check(state);

      // The manifest may have turned pinning off since the stored state was
      // read, and it applies from here on.
      if (mounted) {
        setState(() => _api.pins.killSwitched = !state.pinningEnabled);
        _note('Certificate pinning: ${_api.pins.isEnabled ? "enforced" : "off"}');
      }

      if (available != null) {
        _note('Update available: ${available.latestVersion} (${available.latestVersionCode})');
      }
    } on UpdateRefused catch (e) {
      _note('Update check refused: ${e.reason.name}');
    } catch (e) {
      // A server that is simply unreachable must not stop a driver working.
      _note('Update check failed: $e');
    }
  }

  Future<void> _scan() async {
    final token = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const ScanScreen()),
    );

    if (token == null || !mounted) return;

    await _enroll(token);
  }

  Future<void> _enroll(String activationToken) async {
    setState(() {
      _step = _Step.enrolling;
      _error = null;
      _log.clear();
    });

    try {
      _note('Activation token scanned (${activationToken.length} chars)');
      _note('Generating P-256 key in the Android Keystore…');

      final result = await enrollDevice(
        api: _api,
        state: _state!,
        activationToken: activationToken,
        deviceInfo: await DeviceKey.deviceInfo(),
        uuidFactory: _api.newUuid,
      );

      _note('Key created — StrongBox: ${result.strongBox ? "yes" : "no (TEE)"}');
      _note('Attestation chain: ${result.chainLength} certificates');
      _note('Server accepted enrollment: ${result.deviceId}');

      setState(() {
        _enrollment = result;
        _step = _Step.needsPin;
      });
    } on ApiException catch (e) {
      _note('Server rejected: ${e.code}');
      setState(() {
        _error = _explain(e);
        _step = _Step.needsEnrollment;
      });
    } on PlatformException catch (e) {
      setState(() {
        _error = 'Keystore error: ${e.message}';
        _step = _Step.needsEnrollment;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _step = _Step.needsEnrollment;
      });
    }
  }

  Future<void> _setPin(String pin) async {
    try {
      _note('Signing the PIN request with the device key…');

      await _api.setPin(
        deviceId: _enrollment!.deviceId,
        setupToken: _enrollment!.setupToken,
        pin: pin,
      );

      _note('Signature verified server-side — device is active');
      setState(() {
        _error = null;
        _step = _Step.active;
      });
    } on ApiException catch (e) {
      _note('Server rejected: ${e.code}');
      setState(() => _error = _explain(e));
    }
  }

  /// The server sends English developer text and expects the app to branch on
  /// the code, so nothing here shows `message` to a driver directly.
  String _explain(ApiException e) => switch (e.code) {
        'E_ACTIVATION_CODE_USED' =>
          'This code has already been used or has expired. Ask staff for a new one.',
        'E_DRIVER_HAS_ACTIVE_DEVICE' =>
          'This driver already has a device. Staff must remove the old one first.',
        'E_INTEGRITY_FAILED' => 'This device did not pass the security check.',
        'E_DEVICE_SIGNATURE_INVALID' => 'The device signature was rejected. Enroll again.',
        'E_PIN_LOCKED' => 'Too many wrong attempts. Try again later.',
        'E_RATE_LIMITED' => 'Too many attempts. Wait a moment and try again.',
        _ => '${e.code} — ${e.message}',
      };

  Future<void> _reset() async {
    await _state!.reset();

    setState(() {
      _enrollment = null;
      _error = null;
      _log.clear();
      _step = _Step.needsEnrollment;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Driver enrollment'),
        actions: [
          if (_step != _Step.loading)
            IconButton(
              onPressed: _reset,
              icon: const Icon(Icons.restart_alt),
              tooltip: 'Clear device key',
            ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: SingleChildScrollView(child: _content())),
            if (_log.isNotEmpty) _logPanel(),
          ],
        ),
      ),
    );
  }

  Widget _content() => switch (_step) {
        _Step.loading => const Center(child: Padding(
            padding: EdgeInsets.only(top: 120),
            child: CircularProgressIndicator(),
          )),
        _Step.enrolling => const Column(
            children: [
              SizedBox(height: 100),
              CircularProgressIndicator(),
              SizedBox(height: 16),
              Text('Enrolling…'),
            ],
          ),
        _Step.needsEnrollment => _intro(),
        _Step.needsPin => PinEntry(
            title: 'Set a 6-digit PIN',
            onSubmit: _setPin,
            error: _error,
          ),
        _Step.active => _active(),
      };

  Widget _intro() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SizedBox(height: 40),
          const Icon(Icons.qr_code_scanner, size: 96),
          const SizedBox(height: 24),
          const Text(
            'Scan the activation QR that staff issued for you.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 16),
          ),
          const SizedBox(height: 32),
          FilledButton.icon(
            onPressed: _scan,
            icon: const Icon(Icons.qr_code_scanner),
            label: const Text('Scan QR'),
          ),
          if (_error != null) ...[
            const SizedBox(height: 24),
            _errorBox(_error!),
          ],
          if (_flaggedSignals.isNotEmpty) ...[
            const SizedBox(height: 24),
            _integrityBox(),
          ],
        ],
      );

  List<String> get _flaggedSignals =>
      _integrity.entries.where((e) => e.value).map((e) => e.key).toList();

  /// Amber, not red, and it blocks nothing.
  ///
  /// These signals are forgeable by anything able to act on them, so treating
  /// them as a verdict would punish an honest driver whose phone reports
  /// truthfully while a hidden one passes untouched (§4.1).
  Widget _integrityBox() => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.amber.shade50,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: Colors.amber.shade300),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Device checks flagged: ${_flaggedSignals.join(', ')}',
              style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            Text(
              'Reported to staff. Enrollment is not blocked by this.',
              style: TextStyle(color: Colors.amber.shade900, fontSize: 12),
            ),
          ],
        ),
      );

  Widget _active() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SizedBox(height: 40),
          Icon(Icons.verified_user, size: 96, color: Colors.green.shade700),
          const SizedBox(height: 24),
          const Text(
            'This device is enrolled.',
            textAlign: TextAlign.center,
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 8),
          Text(
            _state?.deviceId ?? '',
            textAlign: TextAlign.center,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 11),
          ),
        ],
      );

  Widget _errorBox(String message) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.red.shade50,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: Colors.red.shade200),
        ),
        child: Text(message, style: TextStyle(color: Colors.red.shade900)),
      );

  Widget _logPanel() => Container(
        constraints: const BoxConstraints(maxHeight: 170),
        margin: const EdgeInsets.only(top: 12),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: Colors.black87,
          borderRadius: BorderRadius.circular(8),
        ),
        child: SingleChildScrollView(
          reverse: true,
          child: Text(
            _log.join('\n'),
            style: const TextStyle(
              color: Colors.greenAccent,
              fontFamily: 'monospace',
              fontSize: 11,
            ),
          ),
        ),
      );
}

/// Camera QR scanner.
///
/// The frame is decoded in memory and discarded — nothing is written to disk or
/// uploaded, which is why the app asks for CAMERA and nothing else.
class ScanScreen extends StatefulWidget {
  const ScanScreen({super.key});

  @override
  State<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends State<ScanScreen> {
  bool _handled = false;

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;

    String? value;
    for (final barcode in capture.barcodes) {
      if (barcode.rawValue != null && barcode.rawValue!.isNotEmpty) {
        value = barcode.rawValue;
        break;
      }
    }

    if (value == null) return;

    // The scanner keeps firing while the code stays in frame; without this the
    // route pops repeatedly and enrollment runs more than once.
    _handled = true;
    Navigator.of(context).pop(value);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Scan activation QR')),
      body: Stack(
        alignment: Alignment.center,
        children: [
          MobileScanner(
            controller: MobileScannerController(
              formats: const [BarcodeFormat.qrCode],
              detectionSpeed: DetectionSpeed.noDuplicates,
            ),
            onDetect: _onDetect,
          ),
          IgnorePointer(
            child: Container(
              width: 240,
              height: 240,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.white70, width: 3),
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class PinEntry extends StatefulWidget {
  const PinEntry({super.key, required this.title, required this.onSubmit, this.error});

  final String title;
  final Future<void> Function(String pin) onSubmit;
  final String? error;

  @override
  State<PinEntry> createState() => _PinEntryState();
}

class _PinEntryState extends State<PinEntry> {
  final _controller = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_controller.text.length != 6 || _busy) return;

    setState(() => _busy = true);

    try {
      await widget.onSubmit(_controller.text);
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _controller.clear();
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 40),
        Text(
          widget.title,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
        ),
        const SizedBox(height: 24),
        TextField(
          controller: _controller,
          keyboardType: TextInputType.number,
          obscureText: true,
          maxLength: 6,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 28, letterSpacing: 12),
          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
          decoration: const InputDecoration(counterText: '', border: OutlineInputBorder()),
          onSubmitted: (_) => _submit(),
        ),
        const SizedBox(height: 8),
        const Text(
          'Avoid 123456, 000000 or a repeated digit — the server rejects those.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 12, color: Colors.black54),
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(
                  height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Confirm'),
        ),
        if (widget.error != null) ...[
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.red.shade50,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: Colors.red.shade200),
            ),
            child: Text(widget.error!, style: TextStyle(color: Colors.red.shade900)),
          ),
        ],
      ],
    );
  }
}
