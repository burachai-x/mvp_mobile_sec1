import 'package:flutter/material.dart';

/// The screen a driver meets every time the app opens.
///
/// A keypad rather than a text field: the PIN is six digits, and a numeric
/// keypad the app draws itself cannot be replaced by a third-party keyboard
/// that logs what is typed.
class LockScreen extends StatefulWidget {
  const LockScreen({
    super.key,
    required this.onPin,
    required this.onBiometric,
    required this.biometricAvailable,
  });

  /// Verified against the server, which owns the lockout.
  final Future<String?> Function(String pin) onPin;

  /// Returns an error to show, or null when the app is unlocked.
  final Future<String?> Function() onBiometric;

  final bool biometricAvailable;

  @override
  State<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends State<LockScreen> {
  static const _length = 6;

  String _pin = '';
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();

    // Offered immediately rather than waiting for a tap: a driver who set this
    // up did so to avoid typing, and the prompt is dismissible back to the pad.
    if (widget.biometricAvailable) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _biometric());
    }
  }

  Future<void> _biometric() async {
    if (_busy) return;

    setState(() {
      _busy = true;
      _error = null;
    });

    final error = await widget.onBiometric();

    if (mounted) {
      setState(() {
        _busy = false;
        _error = error;
      });
    }
  }

  Future<void> _press(String digit) async {
    if (_busy || _pin.length >= _length) return;

    setState(() {
      _pin += digit;
      _error = null;
    });

    if (_pin.length == _length) await _submit();
  }

  Future<void> _submit() async {
    setState(() => _busy = true);

    final error = await widget.onPin(_pin);

    if (mounted) {
      setState(() {
        _busy = false;
        _error = error;
        // Cleared either way. Leaving a rejected PIN on screen invites the
        // driver to edit one digit and burn another attempt against the
        // server's lockout.
        _pin = '';
      });
    }
  }

  void _backspace() {
    if (_busy || _pin.isEmpty) return;

    setState(() => _pin = _pin.substring(0, _pin.length - 1));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            const Spacer(flex: 2),
            Icon(Icons.lock_outline, size: 56, color: Colors.grey.shade700),
            const SizedBox(height: 20),
            const Text('ใส่รหัส PIN', style: TextStyle(fontSize: 18)),
            const SizedBox(height: 24),
            _dots(),
            const SizedBox(height: 20),
            SizedBox(
              height: 40,
              child: _busy
                  ? const SizedBox(
                      height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : _error == null
                      ? const SizedBox.shrink()
                      : Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 24),
                          child: Text(
                            _error!,
                            textAlign: TextAlign.center,
                            style: TextStyle(color: Colors.red.shade700),
                          ),
                        ),
            ),
            const Text(
              'ลืมรหัส PIN ให้ติดต่อเจ้าหน้าที่',
              style: TextStyle(fontSize: 13, color: Colors.black54),
            ),
            const Spacer(flex: 3),
            _keypad(),
            const SizedBox(height: 12),
          ],
        ),
      ),
    );
  }

  Widget _dots() => Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: List.generate(_length, (i) {
          final filled = i < _pin.length;

          return Container(
            width: 16,
            height: 16,
            margin: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: filled ? Theme.of(context).colorScheme.primary : Colors.transparent,
              border: Border.all(color: Theme.of(context).colorScheme.primary, width: 1.5),
            ),
          );
        }),
      );

  Widget _keypad() => Column(
        children: [
          for (final row in const [
            ['1', '2', '3'],
            ['4', '5', '6'],
            ['7', '8', '9'],
          ])
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [for (final digit in row) _key(digit, () => _press(digit))],
            ),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              widget.biometricAvailable
                  ? _key(null, _biometric, icon: Icons.fingerprint)
                  : _key(null, null),
              _key('0', () => _press('0')),
              _key(null, _backspace, icon: Icons.backspace_outlined),
            ],
          ),
        ],
      );

  Widget _key(String? label, VoidCallback? onTap, {IconData? icon}) => SizedBox(
        width: 96,
        height: 68,
        child: InkWell(
          onTap: onTap,
          child: Center(
            child: icon != null
                ? Icon(icon, size: 30, color: Theme.of(context).colorScheme.primary)
                : Text(label ?? '', style: const TextStyle(fontSize: 26)),
          ),
        ),
      );
}
