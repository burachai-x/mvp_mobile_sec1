import 'package:crypto/crypto.dart';
import 'package:driver_app/update_manifest.dart';
import 'package:flutter_test/flutter_test.dart';

/// Checks 5 and 6 of architecture.md §11.3, on the manifest side.
///
/// The download itself needs a device, but the two comparisons that decide
/// whether a file reaches the installer do not — and they are the ones worth
/// pinning down. A manifest can be perfectly signed while the file served under
/// it is something else entirely; these are what notice.
void main() {
  UpdateManifest manifestFor(List<int> apk, {String? cert}) => UpdateManifest(
        package: 'co.th.gbtech.driver_app',
        latestVersion: '1.1.0',
        latestVersionCode: 2,
        minSupportedVersionCode: 1,
        apkUrl: 'https://example.invalid/driver.apk',
        apkSha256: sha256.convert(apk).toString(),
        apkSize: apk.length,
        signingCertSha256: cert ?? 'f66d40a0816d9f08900c8270138a6d7e6728f1fa6946a7bf3ed79f3bc1e9aa2e',
        mandatory: false,
        sequence: 5,
        expiresAt: DateTime.utc(2030),
        certificatePinningEnabled: true,
      );

  group('apk hash (check 5)', () {
    final apk = List<int>.generate(2048, (i) => i % 251);

    test('the described file is accepted', () {
      expect(manifestFor(apk).matchesDownload(apk), isTrue);
    });

    /// A single flipped byte is the realistic case: a file swapped on a CDN, or
    /// a download that finished wrong.
    test('one changed byte is enough to refuse it', () {
      final tampered = [...apk]..[1024] ^= 0x01;

      expect(manifestFor(apk).matchesDownload(tampered), isFalse);
    });

    test('a truncated file is refused', () {
      expect(manifestFor(apk).matchesDownload(apk.sublist(0, apk.length - 1)), isFalse);
    });

    /// Appending to a file leaves the declared prefix intact, so size has to be
    /// checked as well as the digest.
    test('extra bytes on the end are refused', () {
      expect(manifestFor(apk).matchesDownload([...apk, 0]), isFalse);
    });

    test('an empty response is refused', () {
      expect(manifestFor(apk).matchesDownload(const []), isFalse);
    });
  });

  group('signing certificate (check 6)', () {
    final manifest = manifestFor(const [1, 2, 3], cert: 'AABBCC');

    test('the named certificate is accepted, whatever the case', () {
      expect(manifest.matchesSigningCertificate('aabbcc'), isTrue);
      expect(manifest.matchesSigningCertificate('AABBCC'), isTrue);
    });

    test('a different certificate is refused', () {
      expect(manifest.matchesSigningCertificate('aabbcd'), isFalse);
    });

    /// Nothing to compare is a refusal, not a pass. An APK the platform cannot
    /// read a certificate from is exactly the file not to install.
    test('an empty certificate is refused', () {
      expect(manifest.matchesSigningCertificate(''), isFalse);
    });

    test('a prefix of the certificate is refused', () {
      expect(manifest.matchesSigningCertificate('aabb'), isFalse);
    });
  });
}
