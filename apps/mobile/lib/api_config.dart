import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';

import 'certificate_pins.dart';
import 'update_manifest.dart';

/// Build-time configuration.
///
/// Supplied with `--dart-define` rather than a checked-in file so a developer's
/// LAN address never becomes the default for a release build:
///
///   flutter build apk --dart-define=API_BASE_URL=https://api.driver.test \
///                     --dart-define=APP_SIGNATURE=`sha256 of the signing cert`
class ApiConfig {
  const ApiConfig._();

  static const baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://api.invalid',
  );

  /// Layer 1 of the trust model, and a public value — it can be read out of any
  /// published APK with `apksigner`. Shipping it in the binary costs nothing
  /// because it proves nothing; the device key is what authenticates
  /// (architecture.md ADR 0001).
  static const appSignature = String.fromEnvironment('APP_SIGNATURE');

  /// Integer, matched against MIN_SUPPORTED_APP_VERSION on the server. Tracks
  /// the build number in pubspec.yaml.
  static const appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1');

  /// Comma-separated SPKI pins for the API host, and the date they stop being
  /// enforced. Both come from the build so a rotation is a release, not a code
  /// change. Empty means no pinning, which is how the LAN dev build runs.
  ///
  /// See docs/runbook/certificate-pinning.md for how to produce and rotate them.
  static const _pins = String.fromEnvironment('API_CERTIFICATE_PINS');

  static const _pinExpiry = String.fromEnvironment('API_CERTIFICATE_PIN_EXPIRY');

  static CertificatePins certificatePins() {
    final pins = _pins.split(',').map((pin) => pin.trim()).where((pin) => pin.isNotEmpty).toSet();

    if (pins.isEmpty) return CertificatePins.disabled();

    return CertificatePins(
      pins: pins,
      // A pin set with no expiry is one nobody has to remember to rotate, right
      // up until it locks the fleet out (§7).
      expiry: DateTime.parse(_pinExpiry),
    );
  }

  /// A base64 PEM trusted **in addition to** the system roots.
  ///
  /// Exists because Flutter does not use Android's network stack: dart:io talks
  /// to BoringSSL directly and never reads `network_security_config.xml`, so a
  /// trust anchor declared there is silently ignored. Testing against a dev CA
  /// therefore has to happen in code.
  static const _devTrustedCa = String.fromEnvironment('API_DEV_TRUSTED_CA');

  /// The context connections are made with, or null for the default roots.
  ///
  /// Throws in a release build rather than trusting an extra CA: a switch that
  /// widens who may impersonate the API must not be shippable, and the whole
  /// point of pinning is to narrow that set.
  static SecurityContext? securityContext() {
    if (_devTrustedCa.isEmpty) return null;

    if (kReleaseMode) {
      throw StateError('API_DEV_TRUSTED_CA is set in a release build.');
    }

    return SecurityContext(withTrustedRoots: true)
      ..setTrustedCertificatesBytes(base64.decode(_devTrustedCa));
  }

  /// Where the signed manifest lives (§11.3).
  ///
  /// A different host from the API on purpose, so the two can be pinned
  /// separately — and this one is not pinned at all.
  static const manifestUrl = String.fromEnvironment('UPDATE_MANIFEST_URL');

  /// Comma-separated base64 SPKI P-256 keys allowed to sign a manifest.
  ///
  /// The matching private keys never touch a server: whoever can sign can
  /// install software on every driver's phone. More than one so a key can be
  /// rotated — without a spare already embedded, losing the key means no update
  /// can ever be delivered again.
  static const _manifestKeys = String.fromEnvironment('UPDATE_MANIFEST_KEYS');

  static const packageName = 'com.example.driver_app';

  /// Null when no update channel is configured, which is how the dev build runs.
  static ManifestVerifier? manifestVerifier() {
    final keys = _manifestKeys.split(',').map((k) => k.trim()).where((k) => k.isNotEmpty).toList();

    if (manifestUrl.isEmpty || keys.isEmpty) return null;

    return ManifestVerifier(publicKeys: keys, packageName: packageName);
  }
}
