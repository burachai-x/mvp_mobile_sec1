import 'dart:async';

import 'package:flutter/material.dart';

import 'l10n/app_localizations.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'api_client.dart';
import 'app_lock.dart';
import 'api_config.dart';
import 'device_key.dart';
import 'enrollment.dart';
import 'integrity_warning.dart';
import 'lock_screen.dart';
import 'update_checker.dart';
import 'update_manifest.dart';
import 'update_installer.dart';
import 'update_screen.dart';
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
      // Thai first because that is who drives. English is kept complete so a
      // phone set to any other language still reads, rather than falling back
      // to keys (CLAUDE.md section 2).
      locale: const Locale('th'),
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      supportedLocales: AppLocalizations.supportedLocales,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF1B5E20)),
        useMaterial3: true,
      ),
      home: const EnrollScreen(),
    );
  }
}

enum _Step { loading, needsEnrollment, enrolling, needsPin, locked, active }

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
  AppLock? _lock;
  bool _biometricUsable = false;

  /// Held in memory only, for the moment the driver is asked whether to enable
  /// fingerprint unlock. Writing it to disk unsealed would defeat the point of
  /// sealing it.
  String? _pendingRefreshToken;

  /// In memory only. It is a bearer credential, and the device already has a
  /// signing key that cannot be copied off the phone — writing this to disk
  /// would be the weakest thing stored.
  String? _accessToken;

  BiometricAvailability _biometric = BiometricAvailability.unavailable;
  EnrollmentResult? _enrollment;
  String? _error;
  Map<String, bool> _integrity = const {};

  /// Reset every launch on purpose. A warning acknowledged last week says
  /// nothing about the phone the driver is holding now. Never lets a fatal
  /// finding through — that path does not consult it.
  bool _integrityAcknowledged = false;

  bool _recheckingIntegrity = false;

  /// The update a verified manifest offered, if any.
  UpdateManifest? _update;

  /// Skipping lasts for this run only, and is not offered at all when the
  /// server will refuse to talk to this version.
  bool _updateSkipped = false;

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

    final lock = await AppLock.load();
    final biometric = await AppLock.availability();

    setState(() {
      _state = state;
      _lock = lock;
      _integrity = integrity;
      // Offered only when the device can actually gate a key behind it and the
      // driver has already sealed a token; otherwise the pad is all there is.
      _biometric = biometric;
      _biometricUsable =
          lock.biometricEnabled && biometric == BiometricAvailability.available;
      // An enrolled device starts locked. Nothing in the app is reachable until
      // the server has accepted a PIN or a refresh token.
      _step = (state.deviceId != null && hasKey) ? _Step.locked : _Step.needsEnrollment;
    });

    // Deliberately not awaited. Blocking the first frame on a network call left
    // the phone on a black screen for six seconds, and would have waited the
    // full timeout whenever the update host was unreachable — a driver cannot
    // work while the app decides whether a newer version exists.
    _note('Biometric: $lastAvailabilityReport');

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
        securityContext: ApiConfig.securityContext(),
      ).check(state);

      // The manifest may have turned pinning off since the stored state was
      // read, and it applies from here on.
      if (mounted) {
        setState(() => _api.pins.killSwitched = !state.pinningEnabled);
        _note('Certificate pinning: ${_api.pins.isEnabled ? "enforced" : "off"}');
      }

      if (available != null) {
        _note('Update available: ${available.latestVersion} (${available.latestVersionCode})');

        if (mounted) setState(() => _update = available);
      }
    } on UpdateRefused catch (e) {
      _note('Update check refused: ${e.reason.name}');
    } catch (e) {
      // A server that is simply unreachable must not stop a driver working.
      _note('Update check failed: $e');
    }
  }

  /// PIN goes to the server, which owns the lockout and the block-after-N rule.
  /// Checking it locally would be a check the phone's owner could edit.
  Future<String?> _unlockWithPin(String pin) async {
    try {
      final tokens = await _api.verifyPin(deviceId: _state!.deviceId!, pin: pin);
      final refreshToken = tokens['refresh_token'] as String;

      // Reseals when fingerprint unlock is already on, since the token just
      // rotated and the sealed copy is spent.
      await _lock!.resealAfterRefresh(refreshToken);

      _accessToken = tokens['access_token'] as String?;
      unawaited(_reportIntegrity());

      setState(() {
        // Held so the offer to enable fingerprint unlock can appear. Every PIN
        // unlock is a chance to turn it on, not only the first one at
        // enrollment — without this the offer never reaches a driver who
        // enrolled before the feature existed, or who declined it once.
        _pendingRefreshToken = refreshToken;
        _step = _Step.active;
      });

      return null;
    } on ApiException catch (e) {
      // Staff reset the PIN. The device key is untouched, so it can ask for a
      // setup token itself rather than being sent back to enrollment.
      if (e.code == 'E_PIN_RESET_REQUIRED') {
        return await _startPinReset();
      }

      return _explain(e);
    } catch (e) {
      return '$e';
    }
  }

  /// Fetches a setup token and moves to the "set a new PIN" screen.
  Future<String?> _startPinReset() async {
    try {
      final response = await _api.requestPinSetupToken(deviceId: _state!.deviceId!);

      setState(() {
        _enrollment = EnrollmentResult(
          deviceId: _state!.deviceId!,
          setupToken: response['pin_setup_token'] as String,
          chainLength: 0,
          strongBox: false,
        );
        _error = null;
        _step = _Step.needsPin;
      });

      // Whatever was sealed for fingerprint unlock belongs to a session the
      // reset revoked, so it can never be opened into anything usable.
      await _lock!.disableBiometric();
      if (mounted) setState(() => _biometricUsable = false);

      return null;
    } on ApiException catch (e) {
      return _explain(e);
    }
  }

  /// The fingerprint releases a refresh token; the server still decides whether
  /// the session stands, and rotates the token as it does.
  Future<String?> _unlockWithBiometric() async {
    try {
      final refreshToken = await _lock!.unlockWithBiometric();

      final tokens = await _api.refresh(
        deviceId: _state!.deviceId!,
        refreshToken: refreshToken,
      );

      // Refresh tokens rotate, so the sealed copy is spent. Leaving it would
      // give a fingerprint unlock that works exactly once more.
      await _lock!.resealAfterRefresh(tokens['refresh_token'] as String);

      _accessToken = tokens['access_token'] as String?;
      unawaited(_reportIntegrity());

      setState(() => _step = _Step.active);

      return null;
    } on BiometricFailure catch (e) {
      // AppLock has already retired the sealed token in these cases, so the
      // button has to go with it or it would offer something that cannot work.
      const retired = [
        BiometricOutcome.invalidated,
        BiometricOutcome.unusable,
        BiometricOutcome.noneEnrolled,
      ];

      if (retired.contains(e.outcome)) {
        setState(() => _biometricUsable = false);
      }

      return switch (e.outcome) {
        BiometricOutcome.cancelled => null,
        BiometricOutcome.lockout => 'Too many attempts. Enter your PIN instead.',
        BiometricOutcome.invalidated =>
          'A fingerprint was added or removed on this phone, so unlock was turned off. '
              'Enter your PIN, then set it up again.',
        BiometricOutcome.unusable || BiometricOutcome.noneEnrolled =>
          'Fingerprint unlock needs setting up again. Enter your PIN, then turn it on.',
        BiometricOutcome.failed => 'Fingerprint not recognised. Try again or enter your PIN.',
      };
    } on ApiException catch (e) {
      // A refused token means the session is gone, not that the finger was
      // wrong, so the fingerprint option is retired rather than retried.
      await _lock!.disableBiometric();
      setState(() => _biometricUsable = false);

      return _explain(e);
    }
  }

  /// Sends the signals the app already collected, once the device is unlocked.
  ///
  /// Not awaited and never fatal: the warning shown to the driver has already
  /// happened, and a report that cannot be filed must not stop someone working.
  /// It is the fleet's record, not a gate.
  Future<void> _reportIntegrity() async {
    final token = _accessToken;

    if (token == null || _integrity.isEmpty) return;

    try {
      await _api.reportIntegrity(accessToken: token, signals: _integrity);
    } catch (_) {
      // Deliberately quiet. The driver can do nothing with this, and the
      // signals are reported again at the next unlock.
    }
  }

  /// Re-reads the signals so a driver who just turned debugging off is let
  /// through without restarting the app.
  Future<void> _recheckIntegrity() async {
    setState(() => _recheckingIntegrity = true);

    final signals = await DeviceKey.integritySignals();

    if (mounted) {
      setState(() {
        _integrity = signals;
        _recheckingIntegrity = false;
      });
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

      final tokens = await _api.setPin(
        deviceId: _enrollment!.deviceId,
        setupToken: _enrollment!.setupToken,
        pin: pin,
      );

      _note('Signature verified server-side — device is active');
      setState(() {
        _error = null;
        _pendingRefreshToken = tokens['refresh_token'] as String?;
        _step = _Step.active;
      });
    } on ApiException catch (e) {
      _note('Server rejected: ${e.code}');
      setState(() => _error = _explain(e));
    }
  }

  /// The server sends English developer text and expects the app to branch on
  /// the code, so nothing here shows `message` to a driver directly.
  String _explain(ApiException e) {
    final t = AppLocalizations.of(context)!;

    return switch (e.code) {
      'E_PIN_RESET_REQUIRED' => t.errPinResetRequired,
      'E_PIN_ALREADY_SET' => t.errPinAlreadySet,
      // Never tells a driver to enroll again over a typo — that means a trip
      // back to staff for a new activation code.
      'E_PIN_INVALID' => switch (e.details['attempts_remaining']) {
          final int left when left > 0 => t.errPinInvalidAttemptsLeft(left),
          _ => t.errPinInvalid,
        },
      'E_ACTIVATION_CODE_USED' => t.errActivationCodeUsed,
      'E_DRIVER_HAS_ACTIVE_DEVICE' => t.errDriverHasActiveDevice,
      'E_INTEGRITY_FAILED' => t.errIntegrityFailed,
      'E_DEVICE_SIGNATURE_INVALID' => t.errDeviceSignatureInvalid,
      'E_PIN_LOCKED' => switch (e.details['retry_after']) {
          final int seconds when seconds > 0 =>
            t.errPinLockedMinutes((seconds / 60).ceil()),
          _ => t.errPinLocked,
        },
      'E_RATE_LIMITED' => t.errRateLimited,
      _ => t.errUnknown(e.code, e.message),
    };
  }

  /// Seals the current refresh token behind the fingerprint sensor.
  ///
  /// Only offered right after a PIN was accepted, so what gets sealed belongs to
  /// a driver who has just proved they know it.
  Future<void> _enableBiometric() async {
    final token = _pendingRefreshToken;

    if (token == null) return;

    try {
      await _lock!.enableBiometric(token);

      setState(() {
        _biometricUsable = true;
        _pendingRefreshToken = null;
      });

      _note('Fingerprint unlock enabled');
    } on BiometricFailure catch (e) {
      if (e.outcome != BiometricOutcome.cancelled) {
        _note('Fingerprint setup failed: ${e.outcome.name}');
      }
    }
  }

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
    // Ahead of everything, including the lock screen. A fatal finding is not
    // dismissible at all; a warning can be acknowledged, and that
    // acknowledgement lasts only for this run of the app.
    final blocked = _flaggedSignals.isNotEmpty &&
        (signalsAreFatal(_flaggedSignals) || !_integrityAcknowledged);

    if (_step != _Step.loading && blocked) {
      return IntegrityWarning(
        signals: _flaggedSignals,
        busy: _recheckingIntegrity,
        onRecheck: _recheckIntegrity,
        onContinue: () => setState(() => _integrityAcknowledged = true),
      );
    }

    // After the integrity screen, before the lock. A driver whose version the
    // API will refuse cannot get past this; anyone else may put it off until
    // the next launch.
    final update = _update;

    if (update != null && !_updateSkipped && _step != _Step.loading) {
      final mandatory = update.forces(int.parse(ApiConfig.appVersion));

      return UpdateScreen(
        manifest: update,
        installer: UpdateInstaller(securityContext: ApiConfig.securityContext()),
        mandatory: mandatory,
        onSkip: mandatory ? null : () => setState(() => _updateSkipped = true),
      );
    }


    // Replaces the whole scaffold rather than sitting inside it: the app bar
    // carries a button that clears the device key, which must not be reachable
    // before the driver has unlocked.
    if (_step == _Step.locked) {
      return LockScreen(
        onPin: _unlockWithPin,
        onBiometric: _unlockWithBiometric,
        biometricAvailable: _biometricUsable,
        biometricNote: _biometricNote(),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(AppLocalizations.of(context)!.enrollTitle),
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
        _Step.enrolling => Column(
            children: [
              const SizedBox(height: 100),
              const CircularProgressIndicator(),
              const SizedBox(height: 16),
              Text(AppLocalizations.of(context)!.enrolling),
            ],
          ),
        _Step.needsEnrollment => _intro(),
        _Step.needsPin => PinEntry(
            title: AppLocalizations.of(context)!.pinSetTitle,
            onSubmit: _setPin,
            error: _error,
          ),
        _Step.locked => const SizedBox.shrink(),
        _Step.active => _active(),
      };

  Widget _intro() => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SizedBox(height: 40),
          const Icon(Icons.qr_code_scanner, size: 96),
          const SizedBox(height: 24),
          Text(
            AppLocalizations.of(context)!.enrollScanPrompt,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 16),
          ),
          const SizedBox(height: 32),
          FilledButton.icon(
            onPressed: _scan,
            icon: const Icon(Icons.qr_code_scanner),
            label: Text(AppLocalizations.of(context)!.enrollScanButton),
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
              AppLocalizations.of(context)!.integrityFlagged(_flaggedSignals.join(', ')),
              style: TextStyle(color: Colors.amber.shade900, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 4),
            Text(
              AppLocalizations.of(context)!.integrityReported,
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
          Text(
            AppLocalizations.of(context)!.deviceEnrolled,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
          ),
          const SizedBox(height: 8),
          Text(
            _state?.deviceId ?? '',
            textAlign: TextAlign.center,
            style: const TextStyle(fontFamily: 'monospace', fontSize: 11),
          ),
          const SizedBox(height: 32),
          _biometricSetting(),
        ],
      );

  /// One sentence explaining the state of fingerprint unlock, always non-empty.
  String _biometricNote() => switch (_biometric) {
        BiometricAvailability.available =>
          'Fingerprint unlock can be turned on after you enter your PIN.',
        BiometricAvailability.noneEnrolled =>
          'Add a fingerprint in phone settings to unlock without typing.',
        BiometricAvailability.noHardware =>
          'This phone has no fingerprint sensor the app can use.',
        BiometricAvailability.updateRequired =>
          'A security update is needed before fingerprint unlock can be used.',
        BiometricAvailability.unavailable =>
          'Fingerprint unlock unavailable ($lastAvailabilityReport).',
      };

  Widget _biometricSetting() {
    if (_biometricUsable) {
      return TextButton.icon(
        onPressed: () async {
          await _lock!.disableBiometric();
          setState(() => _biometricUsable = false);
        },
        icon: const Icon(Icons.fingerprint),
        label: Text(AppLocalizations.of(context)!.biometricTurnOff),
      );
    }

    // Offered only while a fresh token is in hand. Afterwards the driver enables
    // it the next time they unlock with their PIN, which is when one exists
    // again — the alternative is keeping a token unsealed on disk for the
    // convenience of a settings screen.
    if (_pendingRefreshToken != null && _biometric == BiometricAvailability.available) {
      return FilledButton.icon(
        onPressed: _enableBiometric,
        icon: const Icon(Icons.fingerprint),
        label: Text(AppLocalizations.of(context)!.biometricUseNext),
      );
    }

    // Never renders nothing. A blank space here is what made this look like the
    // driver had done something wrong when the phone simply could not offer it.
    return Text(
      _biometricNote(),
      textAlign: TextAlign.center,
      style: const TextStyle(fontSize: 12, color: Colors.black54),
    );
  }

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
      appBar: AppBar(title: Text(AppLocalizations.of(context)!.scanActivationQr)),
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
        Text(
          AppLocalizations.of(context)!.pinWeakHint,
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 12, color: Colors.black54),
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(
                  height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : Text(AppLocalizations.of(context)!.confirm),
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
