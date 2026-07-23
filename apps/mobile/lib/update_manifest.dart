import 'dart:convert';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:pointycastle/asn1.dart';
import 'package:pointycastle/export.dart';

/// Why an update was refused.
///
/// Named rather than a bare bool: the app has to report which check failed and
/// never retry silently (architecture.md §11.3).
enum UpdateRejection {
  malformed,
  badSignature,
  expired,
  replayedSequence,
  downgrade,
  wrongPackage,
}

class UpdateRefused implements Exception {
  UpdateRefused(this.reason, [this.detail]);

  final UpdateRejection reason;
  final String? detail;

  @override
  String toString() => 'Update refused: ${reason.name}${detail == null ? '' : ' ($detail)'}';
}

/// A manifest whose signature and freshness have been checked.
///
/// Only ever produced by [ManifestVerifier.verify], so holding one means every
/// check in §11.3 that can be made before downloading has passed. The remaining
/// two — the APK hash and its signing certificate — are checked against this
/// object once the file is on disk.
class UpdateManifest {
  UpdateManifest({
    required this.package,
    required this.latestVersion,
    required this.latestVersionCode,
    required this.minSupportedVersionCode,
    required this.apkUrl,
    required this.apkSha256,
    required this.apkSize,
    required this.signingCertSha256,
    required this.mandatory,
    required this.sequence,
    required this.expiresAt,
    required this.certificatePinningEnabled,
    this.releaseNotesTh,
  });

  final String package;
  final String latestVersion;
  final int latestVersionCode;
  final int minSupportedVersionCode;
  final String apkUrl;
  final String apkSha256;
  final int apkSize;
  final String signingCertSha256;
  final bool mandatory;
  final int sequence;
  final DateTime expiresAt;

  /// The kill switch of architecture.md §11.5.
  ///
  /// Pinning can be turned off remotely because a bad pin set locks the fleet
  /// out of the API, and the API is how the fleet would otherwise be reached.
  /// It only arrives over a signed, expiring, non-replayable manifest, and the
  /// update host is deliberately unpinned so this stays reachable when the API
  /// is not.
  final bool certificatePinningEnabled;

  final String? releaseNotesTh;

  /// Whether [installedVersionCode] should be replaced by this release.
  bool isNewerThan(int installedVersionCode) => latestVersionCode > installedVersionCode;

  /// Whether the API will still talk to [installedVersionCode].
  bool forces(int installedVersionCode) =>
      mandatory || installedVersionCode < minSupportedVersionCode;

  /// Confirms the downloaded file is the one the manifest describes (check 5).
  ///
  /// The bytes are hashed whole rather than compared while streaming: a hash
  /// that only matches at the end is worthless if the file was already handed
  /// to the installer.
  bool matchesDownload(List<int> apkBytes) =>
      apkBytes.length == apkSize &&
      _constantTimeEquals(sha256.convert(apkBytes).toString(), apkSha256.toLowerCase());

  /// Confirms the APK was signed by the key that signed the running app
  /// (check 6). [presented] comes from the platform reading the archive.
  bool matchesSigningCertificate(String presented) =>
      _constantTimeEquals(presented.toLowerCase(), signingCertSha256.toLowerCase());

  static bool _constantTimeEquals(String a, String b) {
    if (a.length != b.length) return false;

    var difference = 0;
    for (var i = 0; i < a.length; i++) {
      difference |= a.codeUnitAt(i) ^ b.codeUnitAt(i);
    }

    return difference == 0;
  }

  static UpdateManifest _fromJson(Map<String, dynamic> json) {
    return UpdateManifest(
      package: json['package'] as String,
      latestVersion: json['latest_version'] as String,
      latestVersionCode: json['latest_version_code'] as int,
      minSupportedVersionCode: json['min_supported_version_code'] as int,
      apkUrl: json['apk_url'] as String,
      apkSha256: json['apk_sha256'] as String,
      apkSize: json['apk_size'] as int,
      signingCertSha256: json['signing_cert_sha256'] as String,
      mandatory: json['mandatory'] as bool,
      sequence: json['sequence'] as int,
      expiresAt: DateTime.parse(json['expires_at'] as String),
      // Absent means on. A manifest that forgets the field must not switch
      // pinning off by omission.
      certificatePinningEnabled: json['certificate_pinning_enabled'] as bool? ?? true,
      releaseNotesTh: json['release_notes_th'] as String?,
    );
  }
}

/// Checks a manifest before anything in it is believed (§11.3).
///
/// Whoever controls this endpoint can install software on every driver's phone,
/// so HTTPS is not the control that matters here — it does not survive a
/// breached server, a leaked CDN account or a misissued certificate. The
/// signature does, because the key that makes it is not on any server.
class ManifestVerifier {
  /// [publicKeys] are base64 SPKI DER P-256 keys embedded in the app.
  ///
  /// More than one so a signing key can be rotated: without a spare already
  /// trusted by installed apps, losing the key means no update can ever be
  /// delivered again — and the update channel is the only way to fix an app
  /// that cannot reach the API.
  ManifestVerifier({
    required List<String> publicKeys,
    required this.packageName,
  }) : _publicKeys = publicKeys.map(_parsePublicKey).toList(growable: false) {
    if (publicKeys.isEmpty) {
      throw ArgumentError.value(publicKeys, 'publicKeys', 'At least one key is required.');
    }
  }

  final List<ECPublicKey> _publicKeys;
  final String packageName;

  /// Verifies [document] and applies every check that can be made before the
  /// APK is fetched.
  ///
  /// [lastSequence] is the highest sequence this device has accepted and
  /// [installedVersionCode] the version running now.
  UpdateManifest verify(
    String document, {
    required int lastSequence,
    required int installedVersionCode,
    DateTime? now,
  }) {
    final Map<String, dynamic> envelope;

    try {
      envelope = jsonDecode(document) as Map<String, dynamic>;
    } on FormatException catch (e) {
      throw UpdateRefused(UpdateRejection.malformed, e.message);
    }

    // The signed bytes travel base64-encoded rather than as nested JSON, so the
    // verifier checks exactly what the signer signed. Re-serialising a decoded
    // object to "canonical" JSON invites disagreement over key order, number
    // formatting and escaping between the signing tool and the app — and the
    // symptom is a valid release the fleet refuses.
    final payloadBase64 = envelope['payload'];
    final signatureBase64 = envelope['signature'];

    if (payloadBase64 is! String || signatureBase64 is! String) {
      throw UpdateRefused(UpdateRejection.malformed, 'payload and signature must be strings');
    }

    final Uint8List payloadBytes;
    final Uint8List signatureBytes;

    try {
      payloadBytes = base64.decode(payloadBase64);
      signatureBytes = base64.decode(signatureBase64);
    } on FormatException catch (e) {
      throw UpdateRefused(UpdateRejection.malformed, e.message);
    }

    // Check 1. Nothing below this line runs on an unverified payload.
    if (!_signatureIsValid(payloadBytes, signatureBytes)) {
      throw UpdateRefused(UpdateRejection.badSignature);
    }

    final UpdateManifest manifest;

    try {
      manifest = UpdateManifest._fromJson(
        jsonDecode(utf8.decode(payloadBytes)) as Map<String, dynamic>,
      );
    } catch (e) {
      throw UpdateRefused(UpdateRejection.malformed, '$e');
    }

    // A correctly signed manifest for a different app must not be acted on;
    // one signing key could otherwise move builds between products.
    if (manifest.package != packageName) {
      throw UpdateRefused(UpdateRejection.wrongPackage, manifest.package);
    }

    // Check 2. Without this, serving one stale-but-valid manifest forever pins
    // the fleet to a version whose flaws are known — a freeze attack.
    if (!(now ?? DateTime.now().toUtc()).isBefore(manifest.expiresAt)) {
      throw UpdateRefused(UpdateRejection.expired, manifest.expiresAt.toIso8601String());
    }

    // Check 3. Signatures stay valid forever, so an old manifest replayed later
    // would otherwise be accepted on its own merits.
    if (manifest.sequence <= lastSequence) {
      throw UpdateRefused(
        UpdateRejection.replayedSequence,
        '${manifest.sequence} <= $lastSequence',
      );
    }

    // Check 4. Rolling a device back to a version with a known hole is an
    // attack, not an update — and one that a genuine old manifest enables.
    if (manifest.latestVersionCode < installedVersionCode) {
      throw UpdateRefused(
        UpdateRejection.downgrade,
        '${manifest.latestVersionCode} < $installedVersionCode',
      );
    }

    return manifest;
  }

  bool _signatureIsValid(Uint8List payload, Uint8List signature) {
    final ECSignature? parsed = _decodeSignature(signature);

    if (parsed == null) return false;

    for (final key in _publicKeys) {
      final verifier = ECDSASigner(SHA256Digest())
        ..init(false, PublicKeyParameter<ECPublicKey>(key));

      // Any embedded key may have signed it, which is what makes rotation
      // possible without stranding installed apps.
      if (verifier.verifySignature(payload, parsed)) return true;
    }

    return false;
  }

  /// Reads an ASN.1 DER ECDSA signature, the form openssl and the JDK produce.
  static ECSignature? _decodeSignature(Uint8List der) {
    try {
      final sequence = ASN1Parser(der).nextObject() as ASN1Sequence;
      final elements = sequence.elements;

      if (elements == null || elements.length != 2) return null;

      final r = (elements[0] as ASN1Integer).integer;
      final s = (elements[1] as ASN1Integer).integer;

      if (r == null || s == null) return null;

      return ECSignature(r, s);
    } catch (_) {
      return null;
    }
  }

  static ECPublicKey _parsePublicKey(String base64Spki) {
    final der = base64.decode(base64Spki);
    final sequence = ASN1Parser(der).nextObject() as ASN1Sequence;
    final bitString = sequence.elements![1] as ASN1BitString;

    // The bit string holds an uncompressed EC point, which starts 0x04. Whether
    // the leading unused-bits octet is included depends on the ASN.1 library, so
    // this reads the point rather than assuming an offset — guessing wrong here
    // means the app rejects every genuine update.
    var encoded = Uint8List.fromList(bitString.stringValues!);

    if (encoded.isNotEmpty && encoded.first != 0x04) {
      encoded = Uint8List.sublistView(encoded, 1);
    }

    final domain = ECDomainParameters('prime256v1');

    return ECPublicKey(domain.curve.decodePoint(encoded), domain);
  }
}
