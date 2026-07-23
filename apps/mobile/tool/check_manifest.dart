// ตรวจ manifest ที่เสิร์ฟอยู่จริง — เครื่องมือช่วย debug ไม่ใช่ส่วนหนึ่งของแอป
//
//   dart run tool/check_manifest.dart <url> <pubkey_b64>[,...] [installed_version_code] [ca.crt]
//
// ใช้ verifier ตัวเดียวกับที่แอปใช้ ต่างกันแค่ดึงจาก network แทนรับสตริงมา
import 'dart:convert';
import 'dart:io';

import 'package:driver_app/update_manifest.dart';

Future<void> main(List<String> args) async {
  final url = args[0];
  final keys = args[1].split(',');
  final installed = args.length > 2 ? int.parse(args[2]) : 1;

  final client = HttpClient(
    context: args.length > 3
        ? (SecurityContext(withTrustedRoots: true)..setTrustedCertificates(args[3]))
        : null,
  );
  final response = await (await client.getUrl(Uri.parse(url))).close();
  final document = await response.transform(utf8.decoder).join();
  client.close();

  final verifier = ManifestVerifier(
    publicKeys: keys,
    packageName: 'co.th.gbtech.driver_app',
  );

  try {
    final manifest = verifier.verify(document, lastSequence: 0, installedVersionCode: installed);

    stdout
      ..writeln('signature        : ok')
      ..writeln('version          : ${manifest.latestVersion} (${manifest.latestVersionCode})')
      ..writeln('sequence         : ${manifest.sequence}')
      ..writeln('expires_at       : ${manifest.expiresAt.toIso8601String()}')
      ..writeln('pinning enabled  : ${manifest.certificatePinningEnabled}')
      ..writeln('newer than $installed : ${manifest.isNewerThan(installed)}');
  } on UpdateRefused catch (e) {
    stderr.writeln('REFUSED: ${e.reason.name}${e.detail == null ? "" : " — ${e.detail}"}');
    exit(1);
  }
}
