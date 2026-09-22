import 'package:driver_app/integrity_warning.dart';
import 'package:driver_app/l10n/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Which findings stop the app and which merely warn.
///
/// The split is the whole behaviour: debugging is a setting a driver can turn
/// off, so it lets them carry on; a rooted or hooked platform is not theirs to
/// repair, so it does not.
void main() {
  // Asserting on t.<key> rather than on the Thai text keeps this test about
  // which screen appears. Rewording a string is a translation change and must
  // not read as a behaviour change here.
  late AppLocalizations t;

  setUpAll(() async {
    t = await AppLocalizations.delegate.load(const Locale('th'));
  });

  Widget screen(List<String> signals) => MaterialApp(
        locale: const Locale('th'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
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

    expect(find.text(t.integrityContinue), findsOneWidget);
    expect(find.text(t.integrityRecheck), findsOneWidget);
    expect(find.text(t.integrityCloseApp), findsNothing);

    // The steps are the point of showing this at all.
    expect(find.text(t.integritySteps), findsWidgets);
  });

  testWidgets('a rooted device offers only closing the app', (tester) async {
    await tester.pumpWidget(screen(['rooted', 'usb_debugging']));

    expect(find.text(t.integrityCloseApp), findsOneWidget);
    expect(find.text(t.integrityContinue), findsNothing);
    expect(find.text(t.integrityRecheck), findsNothing);
    expect(find.text(t.integrityContactStaffTitle), findsOneWidget);
  });

  testWidgets('every finding is listed, one per line', (tester) async {
    await tester.pumpWidget(screen(['usb_debugging', 'wireless_debugging', 'developer_options']));

    expect(find.text(t.signalUsbDebugging), findsOneWidget);
    expect(find.text(t.signalWirelessDebugging), findsOneWidget);
    expect(find.text(t.signalDeveloperOptions), findsOneWidget);
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
    expect(find.text(t.integrityCloseApp), findsOneWidget);
  });

  testWidgets('the recheck button reports back', (tester) async {
    var rechecked = 0;

    await tester.pumpWidget(MaterialApp(
      locale: const Locale('th'),
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      supportedLocales: AppLocalizations.supportedLocales,
      home: IntegrityWarning(
        signals: const ['usb_debugging'],
        onRecheck: () async => rechecked++,
        onContinue: () {},
      ),
    ));

    await tester.tap(find.text(t.integrityRecheck));
    await tester.pumpAndSettle();

    expect(rechecked, 1);
  });
}
