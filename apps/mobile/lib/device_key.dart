import 'dart:convert';

import 'package:flutter/services.dart';

/// Bridge to the hardware-backed key in the Android Keystore.
///
/// Everything security-relevant happens on the native side; this class only
/// moves values across. No private key material ever crosses the channel.
class DeviceKey {
  static const _channel = MethodChannel('co.th.gbtech.driver_app/keystore');

  static Future<bool> exists() async =>
      await _channel.invokeMethod<bool>('hasKey') ?? false;

  static Future<Map<String, String>> deviceInfo() async =>
      (await _channel.invokeMapMethod<String, String>('deviceInfo')) ?? const {};

  /// Generates the identity key, attesting to [challenge].
  ///
  /// StrongBox is tried first and dropped on devices without the chip. Refusing
  /// those would exclude most mid-range phones, which is what drivers carry.
  static Future<({String publicKey, List<String> chain, bool strongBox})>
      generate(List<int> challenge) async {
    final encoded = base64.encode(challenge);

    for (final strongBox in [true, false]) {
      try {
        final result = await _channel.invokeMapMethod<String, dynamic>(
          'generate',
          {'challenge': encoded, 'strongBox': strongBox},
        );

        if (result == null) continue;

        return (
          publicKey: result['publicKey'] as String,
          chain: (result['certificateChain'] as List).cast<String>(),
          strongBox: strongBox,
        );
      } on PlatformException {
        if (!strongBox) rethrow;
        // Fall through and retry without StrongBox.
      }
    }

    throw StateError('Could not generate a hardware-backed key on this device.');
  }

  static Future<String> sign(String payload) async {
    final signature = await _channel.invokeMethod<String>('sign', {'payload': payload});

    if (signature == null) {
      throw StateError('Signing returned nothing.');
    }

    return signature;
  }

  static Future<void> clear() => _channel.invokeMethod('clear');
}
