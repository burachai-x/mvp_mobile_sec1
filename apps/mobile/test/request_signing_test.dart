import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:driver_app/api_client.dart';
import 'package:driver_app/l10n/app_localizations.dart';
import 'package:driver_app/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('canonicalString', () {
    // Transcribed from VerifyDeviceSignature, not from the client. If the two
    // disagree the client is wrong, and the only symptom in the field is a
    // blanket E_DEVICE_SIGNATURE_INVALID with nothing pointing at the cause.
    test('matches the format the server rebuilds', () {
      final canonical =
          canonicalString('POST', '/api/v1/auth/pin/verify', '{"a":1}', '1700000000', 'n-1');

      expect(canonical.split('\n'), [
        'POST',
        '/api/v1/auth/pin/verify',
        sha256.convert(utf8.encode('{"a":1}')).toString(),
        '1700000000',
        'n-1',
      ]);
    });

    test('hashes an empty body rather than leaving the line blank', () {
      final canonical = canonicalString('GET', '/api/v1/health', '', '1700000000', 'n-1');

      // sha256 of the empty string: the server hashes request content
      // unconditionally, so a blank line here would never verify.
      expect(
        canonical.split('\n')[2],
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
      );
    });

    test('a different body produces a different string', () {
      final a = canonicalString('POST', '/p', '{"pin":"111111"}', '1700000000', 'n');
      final b = canonicalString('POST', '/p', '{"pin":"222222"}', '1700000000', 'n');

      expect(a, isNot(b));
    });
  });

  group('newUuid', () {
    test('is a v4 UUID', () {
      final uuid = ApiClient('http://x', appSignature: '', appVersion: '1').newUuid();

      expect(
        uuid,
        matches(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')),
      );
    });

    test('does not repeat', () {
      final client = ApiClient('http://x', appSignature: '', appVersion: '1');
      final seen = {for (var i = 0; i < 200; i++) client.newUuid()};

      // A repeat means a reused nonce, which the server's replay guard rejects.
      // Better caught here than as a mystery 401 on a driver's phone.
      expect(seen.length, 200);
    });
  });

  group('PinEntry', () {
    // The button label is translated now, so the test asks the delegate for it
    // instead of hardcoding one language's wording.
    late AppLocalizations t;

    setUpAll(() async {
      t = await AppLocalizations.delegate.load(const Locale('th'));
    });

    testWidgets('will not submit fewer than six digits', (tester) async {
      var submitted = 0;

      await tester.pumpWidget(MaterialApp(
            locale: const Locale('th'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(
          body: PinEntry(title: 'Set a PIN', onSubmit: (_) async => submitted++),
        ),
      ));

      await tester.enterText(find.byType(TextField), '1234');
      await tester.tap(find.text(t.confirm));
      await tester.pump();

      expect(submitted, 0);
    });

    testWidgets('submits once six digits are entered', (tester) async {
      final received = <String>[];

      await tester.pumpWidget(MaterialApp(
            locale: const Locale('th'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(
          body: PinEntry(title: 'Set a PIN', onSubmit: (pin) async => received.add(pin)),
        ),
      ));

      await tester.enterText(find.byType(TextField), '481923');
      await tester.tap(find.text(t.confirm));
      await tester.pumpAndSettle();

      expect(received, ['481923']);
    });

    testWidgets('rejects non-digits before they reach the server', (tester) async {
      await tester.pumpWidget(MaterialApp(
            locale: const Locale('th'),
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
        home: Scaffold(
          body: PinEntry(title: 'Set a PIN', onSubmit: (_) async {}),
        ),
      ));

      await tester.enterText(find.byType(TextField), '12ab34');
      await tester.pump();

      final field = tester.widget<TextField>(find.byType(TextField));
      expect(field.controller!.text, '1234');
    });
  });
}
