import 'package:driver_app/integrity_warning.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Which findings stop the app and which merely warn.
///
/// The split is the whole behaviour: debugging is a setting a driver can turn
/// off, so it lets them carry on; a rooted or hooked platform is not theirs to
/// repair, so it does not.
void main() {
  Widget screen(List<String> signals) => MaterialApp(
        home: IntegrityWarning(
          signals: signals,
          onRecheck: () async {},
          onContinue: () {},
        ),
      );

  group('what counts as fatal', () {
    test('a rooted or tampered platform does', () {
      for (final signal in ['rooted', 'su_binary_found', 'test_keys', 'hook_framework_detected', 'emulator']) {
        expect(signalsAreFatal([signal]), isTrue, reason: signal);
      }
    });

    test('developer settings do not', () {
      for (final signal in ['usb_debugging', 'wireless_debugging', 'developer_options', 'debugger_attached']) {
        expect(signalsAreFatal([signal]), isFalse, reason: signal);
      }
    });

    /// The dangerous case: one fatal finding hidden among harmless ones must
    /// still stop the app.
    test('one fatal finding among others is enough', () {
      expect(signalsAreFatal(['usb_debugging', 'developer_options', 'rooted']), isTrue);
    });

    test('nothing found is not fatal', () {
      expect(signalsAreFatal(const []), isFalse);
    });
  });

  testWidgets('debugging offers a way to carry on', (tester) async {
    await tester.pumpWidget(screen(['usb_debugging', 'developer_options']));

    expect(find.text('ใช้งานต่อ'), findsOneWidget);
    expect(find.text('ตรวจอีกครั้ง'), findsOneWidget);
    expect(find.text('ปิดแอป'), findsNothing);

    // The steps are the point of showing this at all.
    expect(find.textContaining('ตัวเลือกนักพัฒนา'), findsWidgets);
  });

  testWidgets('a rooted device offers only closing the app', (tester) async {
    await tester.pumpWidget(screen(['rooted', 'usb_debugging']));

    expect(find.text('ปิดแอป'), findsOneWidget);
    expect(find.text('ใช้งานต่อ'), findsNothing);
    expect(find.text('ตรวจอีกครั้ง'), findsNothing);
    expect(find.text('กรุณาติดต่อเจ้าหน้าที่'), findsOneWidget);
  });

  testWidgets('every finding is listed, one per line', (tester) async {
    await tester.pumpWidget(screen(['usb_debugging', 'wireless_debugging', 'developer_options']));

    expect(find.text('เปิด USB Debugging อยู่'), findsOneWidget);
    expect(find.text('เปิด Wireless Debugging อยู่'), findsOneWidget);
    expect(find.text('เปิดตัวเลือกนักพัฒนาอยู่'), findsOneWidget);
  });

  /// The screen has to hold together when everything trips at once, which is
  /// where the layout broke before: the list was squeezed and the guidance was
  /// drawn on top of it.
  testWidgets('nine findings at once do not overflow', (tester) async {
    await tester.pumpWidget(screen([
      'rooted',
      'su_binary_found',
      'test_keys',
      'hook_framework_detected',
      'emulator',
      'usb_debugging',
      'wireless_debugging',
      'developer_options',
      'debugger_attached',
    ]));

    expect(tester.takeException(), isNull);
    expect(find.text('ปิดแอป'), findsOneWidget);
  });

  testWidgets('the recheck button reports back', (tester) async {
    var rechecked = 0;

    await tester.pumpWidget(MaterialApp(
      home: IntegrityWarning(
        signals: const ['usb_debugging'],
        onRecheck: () async => rechecked++,
        onContinue: () {},
      ),
    ));

    await tester.tap(find.text('ตรวจอีกครั้ง'));
    await tester.pumpAndSettle();

    expect(rechecked, 1);
  });
}
