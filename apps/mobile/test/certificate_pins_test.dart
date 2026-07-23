import 'dart:convert';

import 'package:driver_app/certificate_pins.dart';
import 'package:flutter_test/flutter_test.dart';

/// Throwaway certificates, generated for these tests only and never used
/// anywhere else. Both expected pins were produced by openssl rather than by
/// this code, so the walker is checked against an independent implementation:
///
///   openssl x509 -in cert.pem -pubkey -noout \
///     | openssl pkey -pubin -outform der \
///     | openssl dgst -sha256 -binary | base64
///
/// RSA and EC are both here because they differ in the shape of the
/// SubjectPublicKeyInfo, which is the part being located.
const _rsaCertificate =
    'MIIDFzCCAf+gAwIBAgIUIVSYkzCRxYQQJcoJsAaCwIlG5nYwDQYJKoZIhvcNAQELBQAwGzEZMBcGA1UEAwwQYXBpLnRlc3QuaW52YWxpZDAeFw0yNjA3MjMwODE4NTJaFw0zNjA3MjAwODE4NTJaMBsxGTAXBgNVBAMMEGFwaS50ZXN0LmludmFsaWQwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQDSQSexHslhFH6hKJqhwx4xAhR2N0f0Egj6BADudT7dq+3lRIRM+Ws6Nqet1IwJ6GX3CMzvix5q7shQ6E6u2cD2y61wOwWHAGx15taUViZ3dcVYqeB8llzBNIHupqQFArGfWG7ohdnlLReHeld++NVVfvOb4UcloISBuZzomBC7S4lZw0Ul4dQ+wLgPLakvKAVkl/xsk4zm8Mfjo/11jedHOJjTsBaEYsUouXDIawCha1nFSXXXYEqgOW/C6CHCvCrrVbHGuyQMljV8cep0kQgnvqFgFojfAnokXktGFaE+G+0InyfiXqIbS6gq+1yrsK0fg9157qB8IiYCnF6MVKslAgMBAAGjUzBRMB0GA1UdDgQWBBSOFEuIO6lEs3ZX9W4f/ePLCurA1TAfBgNVHSMEGDAWgBSOFEuIO6lEs3ZX9W4f/ePLCurA1TAPBgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3DQEBCwUAA4IBAQBWa01GiVZczdFsU8savl2YwPObtMQjMZku5e+yjNc5LsliP0D3XSbH5pCVHfqguOPT40GQy+9Ttelw/+OnuDtE5upqHH2fKr9JTdnct5HN3qxBBXgC2Oa736pnSiKZXP9zsJTdLfgxyU0nZwU1YDPmxYbQETpnpxFOnAzczTkIboNqhUxvkV1lMlg+r0v2IHbVPfCgTS3WCwGNsaGbqRjWAadYsvcjXp/FJYabIt9DKvZGVkf6NDIS4MnBkMynIYPzGebDa4h0KXXuGkf2FMW5Yk9NFaV0e4vIXAATTKEloZWCAS529k/JtqrvLw12RzcibUMirZmUooyrj1FDr9YX';

const _rsaPin = 'KNydRgNoYVvChX6xxsHLWnn4xSRui5RgAbpjcA4u7sU=';

const _ecCertificate =
    'MIIBjTCCATOgAwIBAgIUKfX6gYgbnzsz2ClhHDppBrPaU7gwCgYIKoZIzj0EAwIwHDEaMBgGA1UEAwwRYXBpMi50ZXN0LmludmFsaWQwHhcNMjYwNzIzMDgxODUyWhcNMzYwNzIwMDgxODUyWjAcMRowGAYDVQQDDBFhcGkyLnRlc3QuaW52YWxpZDBZMBMGByqGSM49AgEGCCqGSM49AwEHA0IABLNuIVVl7L+rgkYu1ZYoUT1ToiSbb+cgc6DMTm/CYKh7z/5zIszFDRMDjwqEbgWh36Gtu/BG4niU/7yWIluLCz6jUzBRMB0GA1UdDgQWBBQ+PahXTdWyR/tpTGllD6V782R/GzAfBgNVHSMEGDAWgBQ+PahXTdWyR/tpTGllD6V782R/GzAPBgNVHRMBAf8EBTADAQH/MAoGCCqGSM49BAMCA0gAMEUCICYrA+mc9glFU5cUmdbBb5r9Flp9mLxOu4m9R6JXjKBDAiEAv5N+bn9EDHxAjYADirKuFiGkCyqCZeep+ZWiYuXz1e0=';

const _ecPin = '5RBraChEg287etoAWLThsaANVeBjPRIqQuymLGwrfqE=';

/// Same path production takes, minus the X509Certificate wrapper.
String _pinOf(String certificate) => CertificatePins.pinOfDer(base64.decode(certificate));

void main() {
  group('SubjectPublicKeyInfo', () {
    test('matches what openssl computes for an RSA certificate', () {
      expect(_pinOf(_rsaCertificate), _rsaPin);
    });

    test('matches what openssl computes for an EC certificate', () {
      expect(_pinOf(_ecCertificate), _ecPin);
    });

    test('two different keys do not collide', () {
      expect(_pinOf(_rsaCertificate), isNot(_pinOf(_ecCertificate)));
    });

    test('rejects input that is not a certificate', () {
      expect(
        () => CertificatePins.subjectPublicKeyInfo(const [0x02, 0x01, 0x00]),
        throwsFormatException,
      );
    });

    test('rejects a truncated certificate rather than reading past the end', () {
      final der = base64.decode(_rsaCertificate);

      expect(
        () => CertificatePins.subjectPublicKeyInfo(der.sublist(0, 40)),
        throwsFormatException,
      );
    });
  });

  group('CertificatePins', () {
    final expiry = DateTime.utc(2027, 1, 1);
    final before = DateTime.utc(2026, 12, 31);
    final after = DateTime.utc(2027, 1, 2);

    CertificatePins pins() => CertificatePins(pins: {_rsaPin, _ecPin}, expiry: expiry);

    test('accepts a pinned key', () {
      expect(pins().acceptsPin(_pinOf(_rsaCertificate), now: before), isTrue);
    });

    /// The backup exists so the server can move to a key devices already trust.
    /// If it were not honoured the rotation would fail at the worst moment.
    test('accepts the backup key as readily as the primary', () {
      expect(pins().acceptsPin(_pinOf(_ecCertificate), now: before), isTrue);
    });

    test('does not accept a key it was never given', () {
      expect(pins().acceptsPin('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', now: before), isFalse);
    });

    /// A single pin is a fleet with no way back if that key is lost, so it is
    /// refused outright rather than accepted and quietly weaker (§7).
    test('refuses a pin set with no backup', () {
      expect(
        () => CertificatePins(pins: {_rsaPin}, expiry: expiry),
        throwsA(isA<ArgumentError>()),
      );
    });

    test('no pins at all means pinning is off', () {
      final disabled = CertificatePins.disabled();

      expect(disabled.isEnabled, isFalse);
      expect(disabled.acceptsPin('anything at all', now: before), isTrue);
    });

    group('expiry', () {
      test('is enforced up to the date', () {
        expect(pins().hasLapsed(before), isFalse);
      });

      /// The safety valve. A rotation nobody remembered must degrade to system
      /// trust, not take every installed app offline.
      test('lapses on the date and stays lapsed', () {
        expect(pins().hasLapsed(expiry), isTrue);
        expect(pins().hasLapsed(after), isTrue);
      });

      /// What the valve is for: once lapsed, a key that was never pinned is
      /// accepted, leaving the system trust store as the check.
      test('an unpinned key is accepted once the set has lapsed', () {
        expect(pins().acceptsPin('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', now: before), isFalse);
        expect(pins().acceptsPin('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', now: after), isTrue);
      });
    });

    group('kill switch', () {
      /// A bad pin set locks the fleet out of the API, and the API is how the
      /// fleet would be reached. Turning enforcement off has to be possible —
      /// but only from something signed (see update_manifest_test.dart).
      test('stops enforcement without discarding the pins', () {
        final set = pins()..killSwitched = true;

        expect(set.isEnabled, isFalse);
        expect(set.acceptsPin('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', now: before), isTrue);
        expect(set.pins, isNotEmpty, reason: 'the build still carries its pins');
      });

      test('enforcement resumes when it is switched back on', () {
        final set = pins()..killSwitched = true;
        set.killSwitched = false;

        expect(set.isEnabled, isTrue);
        expect(set.acceptsPin('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', now: before), isFalse);
      });

      test('is off by default', () {
        expect(pins().killSwitched, isFalse);
      });
    });
  });
}
