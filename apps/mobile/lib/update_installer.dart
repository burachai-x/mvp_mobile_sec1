import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import 'update_manifest.dart';

/// Why an update was not installed.
enum InstallFailure {
  /// The download did not finish.
  download,

  /// The file is not the one the manifest describes — checks 5 of §11.3.
  hashMismatch,

  /// The APK is signed by a different key than the manifest names, or than the
  /// running app carries — check 6.
  signatureMismatch,

  /// The user has not allowed this app to install packages.
  permissionRequired,

  handoff,
}

class InstallRefused implements Exception {
  InstallRefused(this.reason, [this.detail]);

  final InstallFailure reason;
  final String? detail;

  @override
  String toString() => 'Update refused: ${reason.name}${detail == null ? '' : ' ($detail)'}';
}

/// Downloads the APK a verified manifest describes and installs it.
///
/// The order is the point. The file is fetched, then hashed, then its signing
/// certificate is read, and only after both match does anything reach the
/// installer. Handing over a file before the checks would make every earlier
/// one decorative — the manifest can be perfect while the file served is not.
class UpdateInstaller {
  const UpdateInstaller();

  static const _channel = MethodChannel('co.th.gbtech.driver_app/keystore');

  /// Reports bytes received against the total, for a progress bar.
  Future<void> download(
    UpdateManifest manifest, {
    void Function(int received, int total)? onProgress,
  }) async {
    final file = await _target(manifest);

    // A partial file from an interrupted attempt would fail the hash anyway,
    // but starting clean keeps the failure honest rather than confusing.
    if (await file.exists()) await file.delete();

    final bytes = await _fetch(manifest, onProgress);

    // Check 5. Hashed whole, in memory, before a single byte reaches disk where
    // the installer could be pointed at it.
    if (!manifest.matchesDownload(bytes)) {
      throw InstallRefused(
        InstallFailure.hashMismatch,
        '${bytes.length} bytes, expected ${manifest.apkSize}',
      );
    }

    await file.writeAsBytes(bytes, flush: true);

    // Check 6, in two halves. The APK has to be signed by the key the manifest
    // names, and by the same key as the running app — the second stops a
    // driver being talked into uninstalling before installing a forgery.
    final presented = await _invoke('apkSigningCertificate', {'path': file.path});
    final own = await _invoke('ownSigningCertificate', const {});

    if (presented == null || !manifest.matchesSigningCertificate(presented)) {
      await file.delete();

      throw InstallRefused(InstallFailure.signatureMismatch, 'manifest');
    }

    if (own == null || presented.toLowerCase() != own.toLowerCase()) {
      await file.delete();

      throw InstallRefused(InstallFailure.signatureMismatch, 'installed app');
    }
  }

  /// Hands the verified file to the system installer.
  Future<void> install(UpdateManifest manifest) async {
    final file = await _target(manifest);

    if (!await file.exists()) {
      throw InstallRefused(InstallFailure.download, 'nothing downloaded');
    }

    if (await _channel.invokeMethod<bool>('canInstallPackages') != true) {
      throw InstallRefused(InstallFailure.permissionRequired);
    }

    try {
      await _channel.invokeMethod('installApk', {'path': file.path});
    } on PlatformException catch (e) {
      throw InstallRefused(InstallFailure.handoff, e.message);
    }
  }

  Future<void> openPermissionSettings() => _channel.invokeMethod('openInstallPermission');

  /// Named after the version, so a stale file from an abandoned attempt cannot
  /// be mistaken for this one.
  Future<File> _target(UpdateManifest manifest) async {
    final directory = Directory('${(await getApplicationSupportDirectory()).path}/updates');

    await directory.create(recursive: true);

    return File('${directory.path}/driver-${manifest.latestVersionCode}.apk');
  }

  Future<List<int>> _fetch(
    UpdateManifest manifest,
    void Function(int, int)? onProgress,
  ) async {
    // Not pinned, deliberately: this is the channel that repairs an app which
    // can no longer reach the API, and its safety comes from the signature on
    // the manifest that named this file (§11.5).
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 20);

    try {
      final response = await (await client.getUrl(Uri.parse(manifest.apkUrl))).close();

      if (response.statusCode != 200) {
        throw InstallRefused(InstallFailure.download, 'HTTP ${response.statusCode}');
      }

      final bytes = <int>[];

      await for (final chunk in response) {
        bytes.addAll(chunk);

        // Stops a server from filling the phone by claiming one size and
        // sending another; the hash would catch it later, but only after the
        // damage.
        if (bytes.length > manifest.apkSize) {
          throw InstallRefused(InstallFailure.hashMismatch, 'longer than declared');
        }

        onProgress?.call(bytes.length, manifest.apkSize);
      }

      return bytes;
    } on SocketException catch (e) {
      throw InstallRefused(InstallFailure.download, e.message);
    } finally {
      client.close();
    }
  }

  Future<String?> _invoke(String method, Map<String, dynamic> arguments) async {
    try {
      return await _channel.invokeMethod<String>(method, arguments);
    } on PlatformException {
      return null;
    }
  }
}

/// Hashes bytes the same way the manifest describes them.
///
/// Exposed so the check can be exercised without a device.
String sha256OfApk(List<int> bytes) => sha256.convert(bytes).toString();
