import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:driver_app/update_manifest.dart';
import 'package:flutter_test/flutter_test.dart';

/// Throwaway P-256 keys, generated for these tests only.
///
/// Both signatures were produced by openssl, not by this code, so the verifier
/// is checked against an independent implementation:
///
///   openssl dgst -sha256 -sign key.pem payload.json | base64 -w0
const _primaryKey =
    'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEGohKOd1g9uyZl9S+i6vMWSzIcN9zeiqbIm0EuziKTrW/GXWtPSGKvdHdLg/9o64GY4Xtvkv4vSglrbk9QsMj9w==';

const _backupKey =
    'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAECp+65Aw9XrexqgFoXIaf8AF5HgRyWqLcIVYyK4cD1ogtKH9lf4RDmdeVxt7Sjo9+iJk5lyDbjocpX7BOJkJfvQ==';

/// The exact bytes that were signed.
const _payload =
    'ewogICJwYWNrYWdlIjogImNvbS5leGFtcGxlLmRyaXZlcl9hcHAiLAogICJsYXRlc3RfdmVyc2lvbiI6ICIxLjEuMCIsCiAgImxhdGVzdF92ZXJzaW9uX2NvZGUiOiAyLAogICJtaW5fc3VwcG9ydGVkX3ZlcnNpb25fY29kZSI6IDEsCiAgImFwa191cmwiOiAiaHR0cHM6Ly9kbC5kcml2ZXIudGVzdC9hcHAvdjEvZHJpdmVyLTEuMS4wLmFwayIsCiAgImFwa19zaGEyNTYiOiAiMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMDAwMCIsCiAgImFwa19zaXplIjogMTIzLAogICJzaWduaW5nX2NlcnRfc2hhMjU2IjogIjExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTExMTEiLAogICJtYW5kYXRvcnkiOiBmYWxzZSwKICAic2VxdWVuY2UiOiA1LAogICJwdWJsaXNoZWRfYXQiOiAiMjAyNi0wNy0yM1QxMDowMDowMFoiLAogICJleHBpcmVzX2F0IjogIjIwMjctMDctMjNUMTA6MDA6MDBaIiwKICAiY2VydGlmaWNhdGVfcGlubmluZ19lbmFibGVkIjogdHJ1ZSwKICAicmVsZWFzZV9ub3Rlc190aCI6ICLguJfguJTguKrguK3guJrguIHguKXguYTguIHguK3guLHguJvguYDguJTguJUiCn0K';

const _signaturePrimary =
    'MEUCIG30vX2i6wc3jchB4kbdjExzfAg4qKJvgkThhzCzgu+JAiEA6PYnt8lJq1DdBnsa+okoxPpyeyZL9YM5hY2F8EKMArc=';

const _signatureBackup =
    'MEYCIQCSZzRs6LDQ+p2BoRfXsNqzxYyMIvhd6AHnhSi7V7tiXAIhAK9Z/s/6u9ammW8BqTmsB4MRSqGb9QAEsJao6jBpncaB';

const _package = 'com.example.driver_app';

/// Comfortably inside the fixture's validity window.
final _now = DateTime.utc(2026, 8, 1);

String _document({String payload = _payload, String signature = _signaturePrimary}) =>
    jsonEncode({'payload': payload, 'signature': signature});

/// Rebuilds the payload with [changes] applied. Used to show that editing a
/// signed manifest invalidates it, which is the whole point of signing it.
String _tamperedPayload(Map<String, dynamic> changes) {
  final payload = jsonDecode(utf8.decode(base64.decode(_payload))) as Map<String, dynamic>;
  payload.addAll(changes);

  return base64.encode(utf8.encode(jsonEncode(payload)));
}

ManifestVerifier _verifier({List<String>? keys}) =>
    ManifestVerifier(publicKeys: keys ?? [_primaryKey, _backupKey], packageName: _package);

UpdateManifest _verify(
  String document, {
  int lastSequence = 0,
  int installedVersionCode = 1,
  DateTime? now,
  List<String>? keys,
}) =>
    _verifier(keys: keys).verify(
      document,
      lastSequence: lastSequence,
      installedVersionCode: installedVersionCode,
      now: now ?? _now,
    );

void main() {
  group('a genuine manifest', () {
    test('is accepted and read correctly', () {
      final manifest = _verify(_document());

      expect(manifest.package, _package);
      expect(manifest.latestVersionCode, 2);
      expect(manifest.sequence, 5);
      expect(manifest.certificatePinningEnabled, isTrue);
      expect(manifest.isNewerThan(1), isTrue);
      expect(manifest.isNewerThan(2), isFalse);
    });

    /// Rotation has to work, or losing the primary key means no update can ever
    /// be shipped again — and updates are the only way to reach an app that
    /// cannot reach the API.
    test('signed by the backup key is accepted too', () {
      expect(
        () => _verify(_document(signature: _signatureBackup)),
        returnsNormally,
      );
    });

    test('is refused once the signing key is no longer embedded', () {
      expect(
        () => _verify(_document(), keys: [_backupKey]),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.badSignature)),
      );
    });
  });

  /// Check 1 — the one the other five rest on. Whoever controls this endpoint
  /// can install software on every driver's phone, and HTTPS does not survive a
  /// breached server or a misissued certificate.
  group('signature (check 1)', () {
    test('a payload edited after signing is refused', () {
      final document = _document(
        payload: _tamperedPayload({'apk_url': 'http://attacker.invalid/evil.apk'}),
      );

      expect(
        () => _verify(document),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.badSignature)),
      );
    });

    /// The field an attacker would reach for first: raise the version code and
    /// the app installs their build as an upgrade.
    test('a raised version code is refused', () {
      final document = _document(payload: _tamperedPayload({'latest_version_code': 999}));

      expect(
        () => _verify(document),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.badSignature)),
      );
    });

    test('a mangled signature is refused rather than throwing', () {
      expect(
        () => _verify(_document(signature: base64.encode(List.filled(70, 9)))),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.badSignature)),
      );
    });

    test('a document that is not a signed manifest is refused', () {
      for (final document in ['not json', '{}', '{"payload": 1, "signature": 2}']) {
        expect(
          () => _verify(document),
          throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.malformed)),
          reason: document,
        );
      }
    });
  });

  /// Check 2 — freeze attack. Serving one stale-but-genuine manifest forever
  /// keeps the fleet on a version whose flaws are known.
  test('an expired manifest is refused (check 2)', () {
    expect(
      () => _verify(_document(), now: DateTime.utc(2028)),
      throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.expired)),
    );
  });

  /// Check 3 — signatures never expire on their own, so an old manifest replayed
  /// later verifies perfectly well.
  group('sequence (check 3)', () {
    /// The same manifest is served on every launch, so seeing it again is the
    /// normal case. Refusing it meant an update could be offered once and never
    /// again — the sequence was recorded on first sight, and the next launch
    /// called the same file a replay.
    test('the same sequence is accepted again', () {
      expect(() => _verify(_document(), lastSequence: 5), returnsNormally);
    });

    test('an older sequence is refused', () {
      expect(
        () => _verify(_document(), lastSequence: 6),
        throwsA(predicate((e) =>
            e is UpdateRefused && e.reason == UpdateRejection.replayedSequence)),
      );
    });

    test('a newer sequence is accepted', () {
      expect(() => _verify(_document(), lastSequence: 4), returnsNormally);
    });
  });

  /// Check 4 — rolling a device back onto a version with a known hole is an
  /// attack, and a genuine old manifest is all it takes.
  group('downgrade (check 4)', () {
    test('an older version code is refused', () {
      expect(
        () => _verify(_document(), installedVersionCode: 3),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.downgrade)),
      );
    });

    test('the same version code is not a downgrade, only nothing to do', () {
      final manifest = _verify(_document(), installedVersionCode: 2);

      expect(manifest.isNewerThan(2), isFalse);
    });
  });

  /// Check 5 — the manifest may be genuine while the file served is not.
  group('apk hash (check 5)', () {
    late UpdateManifest manifest;

    setUp(() => manifest = _verify(_document()));

    test('a file that does not match the manifest is rejected', () {
      expect(manifest.matchesDownload(List.filled(123, 0)), isFalse);
    });

    test('a file of the wrong size is rejected before hashing decides', () {
      expect(manifest.matchesDownload(List.filled(10, 0)), isFalse);
    });

    test('the described file is accepted', () {
      final bytes = List.filled(123, 7);
      final described = UpdateManifest(
        package: _package,
        latestVersion: '1.1.0',
        latestVersionCode: 2,
        minSupportedVersionCode: 1,
        apkUrl: 'http://example.invalid/a.apk',
        apkSha256: sha256.convert(bytes).toString(),
        apkSize: bytes.length,
        signingCertSha256: 'AB',
        mandatory: false,
        sequence: 5,
        expiresAt: DateTime.utc(2027),
        certificatePinningEnabled: true,
      );

      expect(described.matchesDownload(bytes), isTrue);
    });
  });

  /// Check 6 — Android enforces this when installing over an existing app, but
  /// checking first gives an error that can be explained, and catches the trick
  /// of persuading someone to uninstall before installing a forgery.
  test('signing certificate must match, case-insensitively (check 6)', () {
    final manifest = _verify(_document());

    expect(manifest.matchesSigningCertificate(manifest.signingCertSha256.toUpperCase()), isTrue);
    expect(manifest.matchesSigningCertificate('00'), isFalse);
  });

  /// A signing key that could move builds between products is a key that can
  /// install one app's release onto another app's users.
  test('a manifest for a different package is refused', () {
    final verifier = ManifestVerifier(
      publicKeys: [_primaryKey],
      packageName: 'com.example.other',
    );

    expect(
      () => verifier.verify(_document(), lastSequence: 0, installedVersionCode: 1, now: _now),
      throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.wrongPackage)),
    );
  });

  group('certificate pinning kill switch', () {
    test('is on when the manifest says so', () {
      expect(_verify(_document()).certificatePinningEnabled, isTrue);
    });

    /// Turning pinning off is a security-reducing instruction, so it may only
    /// arrive inside something signed. An unsigned edit must not carry it.
    test('cannot be switched off by editing a signed manifest', () {
      final document = _document(
        payload: _tamperedPayload({'certificate_pinning_enabled': false}),
      );

      expect(
        () => _verify(document),
        throwsA(predicate((e) => e is UpdateRefused && e.reason == UpdateRejection.badSignature)),
      );
    });
  });

  group('release notes', () {
    UpdateManifest manifest({
      Map<String, String> byLocale = const {},
      String? legacy,
    }) =>
        UpdateManifest(
          package: _package,
          latestVersion: '1.1.0',
          latestVersionCode: 2,
          minSupportedVersionCode: 1,
          apkUrl: 'https://dl.driver.test/app/v1/driver-1.1.0.apk',
          apkSha256: '0' * 64,
          apkSize: 123,
          signingCertSha256: '1' * 64,
          mandatory: false,
          sequence: 5,
          expiresAt: DateTime.utc(2027),
          certificatePinningEnabled: true,
          releaseNotes: byLocale,
          releaseNotesTh: legacy,
        );

    test('shows the language being asked for', () {
      final m = manifest(byLocale: {'th': 'ไทย', 'en': 'English'});

      expect(m.releaseNotesFor('en'), 'English');
      expect(m.releaseNotesFor('th'), 'ไทย');
    });

    /// A driver whose phone is set to a language nobody translated should not
    /// be shown an empty box where the notes are.
    test('falls back to Thai for a language nobody translated', () {
      expect(manifest(byLocale: {'th': 'ไทย'}).releaseNotesFor('ja'), 'ไทย');
    });

    /// The signing key is offline on purpose, so manifests published before
    /// release_notes existed can never be re-signed with it.
    test('still reads a manifest signed before release_notes existed', () {
      expect(manifest(legacy: 'เก่า').releaseNotesFor('th'), 'เก่า');
      expect(_verify(_document()).releaseNotesFor('th'), isNotNull);
    });

    test('no notes at all is not an error', () {
      expect(manifest().releaseNotesFor('th'), isNull);
    });
  });

  test('a verifier with no keys is refused outright', () {
    expect(
      () => ManifestVerifier(publicKeys: const [], packageName: _package),
      throwsA(isA<ArgumentError>()),
    );
  });
}
