import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:crypto/crypto.dart';

import 'certificate_pins.dart';
import 'device_key.dart';

class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, [this.details = const {}]);

  final int status;
  final String code;
  final String message;
  final Map<String, dynamic> details;

  @override
  String toString() => '$code ($status): $message';
}

/// Talks to the driver API.
///
/// Every call carries the headers the server checks; the ones that matter are
/// the device signature, the timestamp and the nonce, which together stop a
/// captured request from being replayed (architecture.md §6.5).
class ApiClient {
  ApiClient(
    this.baseUrl, {
    required this.appSignature,
    required this.appVersion,
    CertificatePins? pins,
    this.securityContext,
  }) : pins = pins ?? CertificatePins.disabled();

  final String baseUrl;
  final String appSignature;
  final String appVersion;
  final CertificatePins pins;

  /// Extra trust anchors for the handshake. Null uses the system roots, which
  /// is what every real build does.
  final SecurityContext? securityContext;

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

  /// Spends a refresh token for a new pair.
  ///
  /// Rotating, so the token handed in is dead once this returns and the caller
  /// has to store the new one.
  Future<Map<String, dynamic>> refresh({
    required String deviceId,
    required String refreshToken,
  }) {
    return _send('POST', '/api/v1/auth/refresh', {
      'device_id': deviceId,
      'refresh_token': refreshToken,
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

    final client = _client();

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
          // Extra fields the app acts on, such as attempts_remaining on a
          // wrong PIN and retry_after on a lockout.
          error ?? const {},
        );
      }

      return decoded;
    } finally {
      client.close();
    }
  }


  /// An HttpClient that checks the pin before the request is written.
  ///
  /// The socket is created here rather than left to HttpClient, which is what
  /// makes the check useful: taking the certificate off the response would mean
  /// the body — a PIN, a signed payload — had already been sent to whoever
  /// answered. Supplying the socket ourselves means a mismatched server never
  /// receives anything.
  ///
  /// Dart uses the returned socket as-is for a direct connection, so the TLS
  /// session established here is the one the request travels over, not a second
  /// one negotiated afterwards.
  HttpClient _client() {
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 15);

    if (!pins.isEnabled && securityContext == null) return client;

    client.connectionFactory = (uri, proxyHost, proxyPort) async {
      // Pins configured against a cleartext URL is a build mistake, and the
      // dangerous kind: everything works, and nothing is pinned. A build with
      // no pins at all skips this factory entirely, which is how the LAN dev
      // build runs.
      if (uri.scheme != 'https') {
        throw StateError(
          'Certificate pins are configured but $uri is not https, so nothing can be pinned.',
        );
      }

      final task = await SecureSocket.startConnect(
        uri.host,
        uri.port,
        context: securityContext,
        // Chain and hostname are still validated against the system trust
        // store. Pinning narrows what is accepted; it does not replace it.
        onBadCertificate: (_) => false,
      );

      // Awaiting here is what makes the check meaningful: the handshake
      // finishes, the pin is applied, and only then is the task handed back for
      // Dart to write the request over. Throwing leaves the request unsent.
      final socket = await task.socket;
      final certificate = socket.peerCertificate;

      if (certificate == null || !pins.accepts(certificate)) {
        socket.destroy();

        throw CertificatePinMismatch(
          uri.host,
          certificate == null ? 'no certificate' : CertificatePins.pinOf(certificate),
        );
      }

      return task;
    };

    return client;
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
