import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_client.dart';
import 'device_key.dart';

/// What the app remembers between launches.
///
/// Deliberately small: the device id and whether a PIN was set. Access and
/// refresh tokens stay in memory only — writing them to SharedPreferences would
/// put a bearer credential in plain-text app storage, readable on a rooted phone,
/// which is exactly what the hardware key exists to avoid.
class EnrollmentState {
  EnrollmentState(this._prefs);

  static const _deviceIdKey = 'device_id';
  static const _deviceUuidKey = 'device_uuid';

  final SharedPreferences _prefs;

  static Future<EnrollmentState> load() async =>
      EnrollmentState(await SharedPreferences.getInstance());

  String? get deviceId => _prefs.getString(_deviceIdKey);

  Future<void> setDeviceId(String id) => _prefs.setString(_deviceIdKey, id);

  /// A per-install identifier. Not a hardware id — Android has not exposed one
  /// since API 29, and the server treats this as a secondary signal only, storing
  /// it as an HMAC (architecture.md §5).
  Future<String> deviceUuid(String Function() generate) async {
    final existing = _prefs.getString(_deviceUuidKey);
    if (existing != null) return existing;

    final fresh = generate();
    await _prefs.setString(_deviceUuidKey, fresh);

    return fresh;
  }

  Future<void> reset() async {
    await _prefs.remove(_deviceIdKey);
    await DeviceKey.clear();
  }
}

/// Result of a completed enrollment, kept for the diagnostics screen.
class EnrollmentResult {
  EnrollmentResult({
    required this.deviceId,
    required this.setupToken,
    required this.chainLength,
    required this.strongBox,
  });

  final String deviceId;
  final String setupToken;
  final int chainLength;
  final bool strongBox;
}

/// Runs the enrollment described in architecture.md §6.3.
Future<EnrollmentResult> enrollDevice({
  required ApiClient api,
  required EnrollmentState state,
  required String activationToken,
  required Map<String, String> deviceInfo,
  required String Function() uuidFactory,
}) async {
  // The challenge the TEE signs into the attestation. It has to be the same
  // bytes the server derives — sha256 of the activation token — or the chain
  // verifies but belongs to a different enrollment.
  final challenge = sha256.convert(utf8.encode(activationToken)).bytes;

  final key = await DeviceKey.generate(challenge);
  final uuid = await state.deviceUuid(uuidFactory);

  final response = await api.enroll(
    activationToken: activationToken,
    publicKey: key.publicKey,
    certificateChain: key.chain,
    deviceUuid: uuid,
    deviceInfo: deviceInfo,
    // Self-reported and forgeable, which the server knows: these can raise a
    // risk score but never clear one (§4.2).
    integrity: await DeviceKey.integritySignals(),
  );

  final deviceId = response['device_id'] as String;
  await state.setDeviceId(deviceId);

  return EnrollmentResult(
    deviceId: deviceId,
    setupToken: response['pin_setup_token'] as String,
    chainLength: key.chain.length,
    strongBox: key.strongBox,
  );
}
