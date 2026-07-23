/// Build-time configuration.
///
/// Supplied with `--dart-define` rather than a checked-in file so a developer's
/// LAN address never becomes the default for a release build:
///
///   flutter build apk --dart-define=API_BASE_URL=http://192.168.10.53:8080 \
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
}
