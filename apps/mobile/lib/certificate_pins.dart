import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';

/// Thrown when a server presents a certificate whose key is not pinned.
///
/// Distinct from a TLS failure: the chain was valid and the hostname matched.
/// The key was simply not one of ours.
class CertificatePinMismatch implements Exception {
  CertificatePinMismatch(this.host, this.presented);

  final String host;
  final String presented;

  @override
  String toString() => 'Certificate pin mismatch for $host (presented $presented).';
}

/// SPKI pins for the system API (architecture.md §6.7).
///
/// Pins the public key, not the certificate: renewing a certificate with the
/// same key keeps working, which is the ordinary case and must not require an
/// app release.
///
/// Only the API is pinned. The update host is deliberately not — it is the
/// recovery channel, and a pin failure there would leave the whole fleet with no
/// way to be fixed. Its safety comes from the signature on the manifest instead.
/// Garage is never reached from the app at all.
class CertificatePins {
  /// [pins] are base64 SHA-256 hashes of the certificate's SubjectPublicKeyInfo:
  ///
  ///   openssl x509 -in cert.pem -pubkey -noout \
  ///     | openssl pkey -pubin -outform der \
  ///     | openssl dgst -sha256 -binary | base64
  ///
  /// [expiry] is the date the pin set stops being enforced, after which
  /// validation falls back to the system trust store. Chain validation is
  /// unaffected either way — a lapsed pin set loosens the check, it does not
  /// remove it. Without this a forgotten rotation would take every installed
  /// app offline with no way to reach them.
  CertificatePins({required Set<String> pins, required this.expiry}) : pins = Set.unmodifiable(pins) {
    if (pins.length == 1) {
      // One pin means one certificate key stands between the fleet and being
      // unreachable. A backup lets the server move to a new key that devices
      // already trust (§7).
      throw ArgumentError.value(
        pins,
        'pins',
        'Pinning needs a backup pin. Configure at least two, or none at all.',
      );
    }
  }

  /// No pinning. The dev build talks to a LAN host over plain HTTP, where there
  /// is no certificate to pin in the first place.
  CertificatePins.disabled() : pins = const {}, expiry = null;

  final Set<String> pins;
  final DateTime? expiry;

  bool get isEnabled => pins.isNotEmpty;

  bool hasLapsed(DateTime now) => expiry != null && !now.isBefore(expiry!);

  /// Whether a certificate presenting [pin] may be trusted.
  ///
  /// [now] is injected so the lapse behaviour can be tested; production passes
  /// the wall clock.
  bool acceptsPin(String pin, {DateTime? now}) {
    if (!isEnabled) return true;
    if (hasLapsed(now ?? DateTime.now())) return true;

    return pins.contains(pin);
  }

  bool accepts(X509Certificate certificate, {DateTime? now}) =>
      acceptsPin(pinOf(certificate), now: now);

  /// The pin a certificate presents, in the same form as [pins].
  static String pinOf(X509Certificate certificate) => pinOfDer(certificate.der);

  /// Split out from [pinOf] so the same path can be exercised against a DER on
  /// disk; an X509Certificate only exists once a socket is connected.
  static String pinOfDer(List<int> der) =>
      base64.encode(sha256.convert(subjectPublicKeyInfo(der)).bytes);

  /// Extracts the SubjectPublicKeyInfo, tag and length included, from a DER
  /// certificate.
  ///
  /// X.509 fixes the position, so this walks rather than searches:
  ///
  ///   Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signature }
  ///   TBSCertificate ::= SEQUENCE {
  ///     [0] version OPTIONAL, serialNumber, signature, issuer, validity,
  ///     subject, subjectPublicKeyInfo, ... }
  ///
  /// Hand-walked rather than pulling in an ASN.1 package: the path is six fixed
  /// steps and adding a dependency on the one code path that decides whether the
  /// app can reach the server buys nothing. The tests check the result against
  /// openssl for both an RSA and an EC certificate.
  static List<int> subjectPublicKeyInfo(List<int> der) {
    final certificate = _contentsOfSequence(der, 0);
    final tbs = _contentsOfSequence(der, certificate.start);

    var offset = tbs.start;

    // [0] EXPLICIT version, present on v2 and v3 certificates only.
    if (der[offset] == 0xa0) {
      offset = _element(der, offset).end;
    }

    // serialNumber, signature, issuer, validity, subject.
    for (var i = 0; i < 5; i++) {
      offset = _element(der, offset).end;
    }

    final spki = _element(der, offset);

    if (der[spki.tag] != 0x30) {
      throw const FormatException('SubjectPublicKeyInfo is not a SEQUENCE.');
    }

    return der.sublist(spki.tag, spki.end);
  }

  static _Element _contentsOfSequence(List<int> der, int offset) {
    final element = _element(der, offset);

    if (der[element.tag] != 0x30) {
      throw const FormatException('Expected a DER SEQUENCE.');
    }

    return element;
  }

  /// Reads the element starting at [offset], returning where its contents begin
  /// and where the whole element ends.
  static _Element _element(List<int> der, int offset) {
    if (offset + 1 >= der.length) {
      throw const FormatException('Truncated DER.');
    }

    final lengthByte = der[offset + 1];
    var start = offset + 2;
    var length = lengthByte;

    if (lengthByte & 0x80 != 0) {
      final byteCount = lengthByte & 0x7f;

      // Indefinite length is not permitted in DER, and anything above four
      // bytes describes a certificate larger than any that exists.
      if (byteCount == 0 || byteCount > 4) {
        throw const FormatException('Unsupported DER length.');
      }

      length = 0;
      for (var i = 0; i < byteCount; i++) {
        length = (length << 8) | der[start + i];
      }
      start += byteCount;
    }

    final end = start + length;

    if (end > der.length) {
      throw const FormatException('DER element runs past the end.');
    }

    return _Element(tag: offset, start: start, end: end);
  }
}

class _Element {
  const _Element({required this.tag, required this.start, required this.end});

  /// Offset of the tag byte.
  final int tag;

  /// Offset of the first content byte.
  final int start;

  /// Offset just past the last content byte.
  final int end;
}
