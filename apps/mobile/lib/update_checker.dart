import 'dart:convert';
import 'dart:io';

import 'update_manifest.dart';
import 'update_state.dart';

/// Fetches the manifest and applies what it says (§11.3).
///
/// The update host is reached with an ordinary client and is deliberately not
/// pinned: it is the channel that fixes an app which can no longer reach the
/// API, so it must stay reachable when pinning is exactly what went wrong. Its
/// safety comes from the signature on the manifest, which survives a breached
/// server — TLS does not.
class UpdateChecker {
  UpdateChecker({
    required this.manifestUrl,
    required this.verifier,
    required this.installedVersionCode,
  });

  final String manifestUrl;
  final ManifestVerifier verifier;
  final int installedVersionCode;

  /// Returns the verified manifest, or null if there is nothing to act on.
  ///
  /// A failed check is never retried quietly: it is thrown so the caller can
  /// report it. A manifest that fails verification is either a mistake in
  /// releasing or someone trying to install software on a driver's phone, and
  /// neither should pass unnoticed.
  Future<UpdateManifest?> check(UpdateState state) async {
    final document = await _fetch();

    final manifest = verifier.verify(
      document,
      lastSequence: state.sequence,
      installedVersionCode: installedVersionCode,
    );

    // Recorded before anything is downloaded. The manifest has already been
    // proven fresh and genuine, and its instructions — the pinning kill switch
    // among them — must take effect even if no APK is fetched.
    await state.accept(manifest);

    return manifest.isNewerThan(installedVersionCode) ? manifest : null;
  }

  Future<String> _fetch() async {
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 15);

    try {
      final request = await client.getUrl(Uri.parse(manifestUrl));
      final response = await request.close();

      if (response.statusCode != 200) {
        throw UpdateRefused(UpdateRejection.malformed, 'HTTP ${response.statusCode}');
      }

      return await response.transform(utf8.decoder).join();
    } finally {
      client.close();
    }
  }
}
