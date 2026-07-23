import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';

import 'device_key.dart';

class ApiException implements Exception {
  ApiException(this.status, this.code, this.message);

  final int status;
  final String code;
  final String message;

  @override
  String toString() => '$code ($status): $message';
}

/// Talks to the driver API.
///
/// Every call carries the headers the server checks; the ones that matter are
/// the device signature, the timestamp and the nonce, which together stop a
/// captured request from being replayed (architecture.md §6.5).
class ApiClient {
  ApiClient(this.baseUrl, {required this.appSignature, required this.appVersion});

  final String baseUrl;
  final String appSignature;
  final String appVersion;

  final _random = Random.secure();

  Future<Map<String, dynamic>> enroll({
    required String activationToken,
    required String publicKey,
    required List<String> certificateChain,
    required String deviceUuid,
    required Map<String, String> deviceInfo,
    required Map<String, bool> integrity,
  }) {
    return _send(
      'POST',
      '/api/v1/devices/enroll',
      {
        'activation_token': activationToken,
        'public_key': publicKey,
        'device_uuid': deviceUuid,
        'device_info': deviceInfo,
        'key_attestation': {'certificate_chain': certificateChain},
        'integrity': integrity,
      },
      // The key was only just created, so the server has nothing to verify a
      // signature against yet — this is the one unsigned call.
      signed: false,
    );
  }

  Future<Map<String, dynamic>> setPin({
    required String deviceId,
    required String setupToken,
    required String pin,
  }) {
    return _send('POST', '/api/v1/devices/$deviceId/pin', {
      'pin_setup_token': setupToken,
      'pin': pin,
    });
  }

  Future<Map<String, dynamic>> verifyPin({
    required String deviceId,
    required String pin,
  }) {
    return _send('POST', '/api/v1/auth/pin/verify', {
      'device_id': deviceId,
      'pin': pin,
    });
  }

  Future<Map<String, dynamic>> health() => _send('GET', '/api/v1/health', null, signed: false);

  Future<Map<String, dynamic>> _send(
    String method,
    String path,
    Map<String, dynamic>? payload, {
    bool signed = true,
  }) async {
    // Encoded once and reused: the signature covers a hash of these exact bytes,
    // so re-encoding could produce a different ordering and a signature the
    // server cannot reproduce.
    final body = payload == null ? '' : jsonEncode(payload);

    final timestamp = (DateTime.now().millisecondsSinceEpoch ~/ 1000).toString();
    final nonce = newUuid();

    final headers = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-App-Signature': appSignature,
      'X-App-Version': appVersion,
      'X-Request-Id': newUuid(),
      'X-Timestamp': timestamp,
      'X-Nonce': nonce,
    };

    if (signed) {
      headers['X-Device-Signature'] =
          await DeviceKey.sign(canonicalString(method, path, body, timestamp, nonce));
    }

    final client = HttpClient()..connectionTimeout = const Duration(seconds: 15);

    try {
      final request = await client.openUrl(method, Uri.parse('$baseUrl$path'));
      headers.forEach(request.headers.set);

      if (body.isNotEmpty) {
        request.add(utf8.encode(body));
      }

      final response = await request.close();
      final text = await response.transform(utf8.decoder).join();
      final decoded = text.isEmpty ? <String, dynamic>{} : jsonDecode(text) as Map<String, dynamic>;

      if (response.statusCode >= 400) {
        final error = decoded['error'] as Map<String, dynamic>?;

        // The server sends English text for developers and expects the app to
        // branch on the code, so this keeps both rather than showing `message`
        // to a driver.
        throw ApiException(
          response.statusCode,
          error?['code'] as String? ?? 'E_UNKNOWN',
          error?['message'] as String? ?? text,
        );
      }

      return decoded;
    } finally {
      client.close();
    }
  }

  /// UUID v4 from a CSPRNG. Public so the enrollment flow can mint the
  /// per-install identifier without pulling in another package.
  String newUuid() {
    final bytes = List<int>.generate(16, (_) => _random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    final hex = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();

    return '${hex.substring(0, 8)}-${hex.substring(8, 12)}-${hex.substring(12, 16)}'
        '-${hex.substring(16, 20)}-${hex.substring(20)}';
  }
}

/// The exact string the server rebuilds and verifies against, per
/// docs/api/openapi.yaml and VerifyDeviceSignature:
///
///   METHOD \n PATH \n sha256_hex(body) \n timestamp \n nonce
///
/// Top-level and tested rather than inlined: a one-character drift here fails
/// every signed request with the same opaque E_DEVICE_SIGNATURE_INVALID, with
/// nothing on either side pointing at the cause.
String canonicalString(
  String method,
  String path,
  String body,
  String timestamp,
  String nonce,
) {
  return [
    method,
    path,
    sha256.convert(utf8.encode(body)).toString(),
    timestamp,
    nonce,
  ].join('\n');
}
