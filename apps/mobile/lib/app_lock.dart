import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Why a biometric attempt did not produce the secret.
enum BiometricOutcome {
  cancelled,
  lockout,
  noneEnrolled,

  /// A fingerprint was added or removed, destroying the key on purpose.
  invalidated,

  /// The sealed value can never be opened again — no key, or one left by an
  /// older build. Separate from [failed], which is worth retrying.
  unusable,

  failed,
}

class BiometricFailure implements Exception {
  BiometricFailure(this.outcome);

  final BiometricOutcome outcome;

  /// Named, because the default rendering of an exception is
  /// "Instance of 'BiometricFailure'", which is what a driver saw on screen the
  /// first time one escaped.
  @override
  String toString() => 'Fingerprint unlock failed: ${outcome.name}';
}

/// Whether the device can gate a key behind a fingerprint.
enum BiometricAvailability { available, noneEnrolled, noHardware, updateRequired, unavailable }

/// What the platform reported, kept verbatim for the diagnostics panel.
///
/// Without it an unexpected status collapses into "biometrics are off" with no
/// way to tell why — which is exactly what happened the first time this ran on
/// a handset.
String lastAvailabilityReport = 'not checked';

/// Locks the app behind the driver's PIN, with a fingerprint as a shortcut.
///
/// The PIN is the one set at enrollment and checked by the server, which is
/// where the lockout lives. A fingerprint never replaces that check: it releases
/// a refresh token the server then rotates, so the server still decides whether
/// the session stands.
///
/// The sealed token is stored here in ordinary preferences on purpose. It is
/// ciphertext whose key lives in the TEE and will not run without a biometric,
/// so copying the file off the phone yields nothing usable.
class AppLock {
  AppLock(this._prefs);

  static const _channel = MethodChannel('com.example.driver_app/keystore');
  static const _sealedKey = 'biometric_refresh_token';

  final SharedPreferences _prefs;

  static Future<AppLock> load() async => AppLock(await SharedPreferences.getInstance());

  bool get biometricEnabled => _prefs.getString(_sealedKey) != null;

  static Future<BiometricAvailability> availability() async {
    final String value;

    try {
      value = await _channel.invokeMethod<String>('biometricAvailability') ?? 'unavailable:null';
    } on PlatformException catch (e) {
      lastAvailabilityReport = 'error:${e.code}';

      return BiometricAvailability.unavailable;
    }

    lastAvailabilityReport = value;

    return switch (value) {
      'available' => BiometricAvailability.available,
      'none_enrolled' => BiometricAvailability.noneEnrolled,
      'no_hardware' => BiometricAvailability.noHardware,
      'update_required' => BiometricAvailability.updateRequired,
      _ => BiometricAvailability.unavailable,
    };
  }

  /// Seals [refreshToken] behind the fingerprint sensor.
  ///
  /// Called after a PIN unlock succeeded, so the token being sealed is one the
  /// server has just issued to a driver who proved they know the PIN.
  Future<void> enableBiometric(String refreshToken) async {
    final sealed = await _invoke('biometricEnroll', {'secret': refreshToken});

    await _prefs.setString(_sealedKey, sealed);
  }

  /// Returns the refresh token, once a fingerprint has been accepted.
  Future<String> unlockWithBiometric() async {
    final sealed = _prefs.getString(_sealedKey);

    if (sealed == null) {
      throw BiometricFailure(BiometricOutcome.noneEnrolled);
    }

    try {
      return await _invoke('biometricUnlock', {'sealed': sealed});
    } on BiometricFailure catch (e) {
      // Whenever the sealed value can no longer be opened, retire it. Keeping
      // it leaves a fingerprint button that fails every time, for good, with
      // nothing telling the driver to set it up again.
      if (e.outcome == BiometricOutcome.invalidated ||
          e.outcome == BiometricOutcome.unusable ||
          e.outcome == BiometricOutcome.noneEnrolled) {
        await disableBiometric();
      }

      rethrow;
    }
  }

  Future<void> disableBiometric() async {
    await _prefs.remove(_sealedKey);
    await _channel.invokeMethod('biometricForget');
  }

  /// Replaces the sealed token after every refresh.
  ///
  /// Refresh tokens rotate, so the stored one is spent the moment it is used.
  /// Leaving it in place would give a fingerprint unlock that works once more
  /// and then stops.
  ///
  /// Seals with the public half, so this never prompts. It also never throws:
  /// the driver has already been let in by this point, and failing to prepare
  /// the *next* unlock is not a reason to fail this one.
  Future<void> resealAfterRefresh(String refreshToken) async {
    if (!biometricEnabled) return;

    try {
      await _prefs.setString(_sealedKey, await _invoke('biometricSeal', {'secret': refreshToken}));
    } on BiometricFailure {
      // The stored copy is now stale, so retire the option rather than leave a
      // fingerprint unlock that will fail next time with no explanation.
      await disableBiometric();
    }
  }

  Future<String> _invoke(String method, Map<String, dynamic> arguments) async {
    try {
      final value = await _channel.invokeMethod<String>(method, arguments);

      if (value == null) throw BiometricFailure(BiometricOutcome.failed);

      return value;
    } on PlatformException catch (e) {
      throw BiometricFailure(switch (e.code) {
        'cancelled' => BiometricOutcome.cancelled,
        'lockout' => BiometricOutcome.lockout,
        'none_enrolled' => BiometricOutcome.noneEnrolled,
        'invalidated' => BiometricOutcome.invalidated,
        'unusable' || 'no_key' => BiometricOutcome.unusable,
        _ => BiometricOutcome.failed,
      });
    }
  }
}
