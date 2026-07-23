// ตรวจ pin กับ TLS handshake จริง — เครื่องมือช่วย debug ไม่ใช่ส่วนหนึ่งของแอป
//
//   dart run tool/check_pin.dart 192.168.10.53 8443 <ca.crt> [pin,pin]
//
// ใช้โค้ดชุดเดียวกับที่แอปใช้ ต่างกันแค่ตรงนี้ต่อจริงแทนที่จะอ่าน DER จากไฟล์
import 'dart:io';

import 'package:driver_app/certificate_pins.dart';

Future<void> main(List<String> args) async {
  final host = args[0];
  final port = int.parse(args[1]);
  final context = SecurityContext(withTrustedRoots: true)..setTrustedCertificates(args[2]);

  final pins = args.length > 3
      ? CertificatePins(pins: args[3].split(',').toSet(), expiry: DateTime.utc(2099))
      : CertificatePins.disabled();

  final socket = await SecureSocket.connect(
    host,
    port,
    context: context,
    onBadCertificate: (_) => false,
  );

  final certificate = socket.peerCertificate!;
  final presented = CertificatePins.pinOf(certificate);

  stdout
    ..writeln('chain + hostname : validated (onBadCertificate rejects everything)')
    ..writeln('subject          : ${certificate.subject.trim()}')
    ..writeln('issuer           : ${certificate.issuer.trim()}')
    ..writeln('presented pin    : $presented')
    ..writeln('configured pins  : ${pins.isEnabled ? pins.pins.join(", ") : "(none)"}')
    ..writeln('accepted         : ${pins.accepts(certificate)}');

  await socket.close();
  exit(pins.accepts(certificate) ? 0 : 1);
}
