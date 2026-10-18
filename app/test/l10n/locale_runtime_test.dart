import 'package:flutter/material.dart';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/batch2_fakes.dart';
import '../helpers/batch2_json.dart';
import '../helpers/fakes.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

ProviderContainer containerOf(WidgetTester tester) =>
    ProviderScope.containerOf(tester.element(find.byType(MaterialApp)));

Future<void> chooseLanguage(WidgetTester tester, String code) async {
  await containerOf(tester)
      .read(localeControllerProvider.notifier)
      .choose(code, signedIn: true);
  await settle(tester, seconds: 1);
}

void main() {
  testWidgets('first run follows an Indonesian device language', (
    tester,
  ) async {
    tester.platformDispatcher.localesTestValue = const [Locale('id', 'ID')];
    addTearDown(tester.platformDispatcher.clearLocalesTestValue);
    await pumpApp(tester, TestEnv());

    expect(find.text('Check-in'), findsWidgets);
    expect(find.text('Check In'), findsNothing);
    await unmount(tester);
  });

  testWidgets('an unsupported device language falls back to English', (
    tester,
  ) async {
    tester.platformDispatcher.localesTestValue = const [Locale('fr', 'FR')];
    addTearDown(tester.platformDispatcher.clearLocalesTestValue);
    await pumpApp(tester, TestEnv());

    expect(find.text('Check In'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('a saved choice beats the device language', (tester) async {
    tester.platformDispatcher.localesTestValue = const [Locale('id', 'ID')];
    addTearDown(tester.platformDispatcher.clearLocalesTestValue);
    await pumpApp(tester, TestEnv(), initialPrefs: {'settings.locale': 'en'});
    expect(find.text('Check In'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets('switching language at runtime relocalizes without a restart', (
    tester,
  ) async {
    final env = TestEnv();
    await pumpApp(tester, env);
    expect(find.text('Check In'), findsOneWidget);
    expect(find.text('Attendance'), findsWidgets);

    await chooseLanguage(tester, 'id');
    expect(find.text('Check-in'), findsWidgets);
    expect(find.text('Check In'), findsNothing);
    expect(find.text('Absensi'), findsWidgets);
    expect(
      find.text('Attendance'),
      findsNothing,
      reason: 'no mixed-language chrome',
    );

    await chooseLanguage(tester, 'en');
    expect(find.text('Check In'), findsOneWidget);
    await unmount(tester);
  });

  testWidgets(
    'the choice is persisted locally and sent to the server when signed in',
    (tester) async {
      final env = TestEnv();
      await pumpApp(tester, env);
      await chooseLanguage(tester, 'id');

      final prefs = await SharedPreferences.getInstance();
      expect(prefs.getString('settings.locale'), 'id');
      expect(env.auth.savedLocales, ['id']);
      await unmount(tester);
    },
  );

  testWidgets('server preference wins after sign-in', (tester) async {
    final env = TestEnv(
      auth: FakeAuthRepository(signedIn: true)..serverLocale = 'id',
    );
    await pumpApp(tester, env);
    expect(find.text('Check-in'), findsWidgets);
    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('settings.locale'), 'id');
    await unmount(tester);
  });

  testWidgets('a failing server save keeps the local choice and says so', (
    tester,
  ) async {
    final env = TestEnv();
    env.auth.savePreferenceResult = false;
    await pumpApp(tester, env);
    await tester.tap(find.text('Profile').last);
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Settings'));
    await settle(tester, seconds: 1);
    await tester.tap(find.text('Bahasa Indonesia'));
    await settle(tester, seconds: 1);

    expect(find.textContaining('belum bisa disimpan'), findsOneWidget);
    await unmount(tester);
  });

  group('Indonesian screens contain Indonesian text and localized formats', () {
    Future<void> inId(WidgetTester tester, TestEnv env) =>
        pumpApp(tester, env, initialPrefs: {'settings.locale': 'id'});

    testWidgets(
      'payslip: Indonesian labels and IDR with Indonesian separators',
      (tester) async {
        final env = TestEnv(
          payslips: FakePayslipRepository(
            items: [FakePayslipRepository.sample()],
          ),
        );
        await inId(tester, env);
        await tester.tap(find.text('Slip gaji').last);
        await settle(tester, seconds: 1);
        expect(find.text('Rp 10.615.000'), findsWidgets);
        expect(find.text('Rp 10,615,000'), findsNothing);
        await tester.tap(find.textContaining('Agustus 2026').first);
        await settle(tester, seconds: 1);
        expect(find.text('Pendapatan'), findsOneWidget);
        expect(find.text('Potongan'), findsWidgets);
        // Server text is not translated.
        expect(find.text('Basic Salary'), findsOneWidget);
        await unmount(tester);
      },
    );

    testWidgets('leave: status badges, dates and balance text', (tester) async {
      final env = TestEnv()
        ..leave.requestList = [
          LeaveRequest.fromJson(JsonReader(leaveRequestJson())),
        ];
      await inId(tester, env);
      await tester.tap(find.text('Pengajuan').last);
      await settle(tester, seconds: 1);
      expect(find.text('Menunggu'), findsWidgets);
      expect(find.textContaining('Okt 2026'), findsWidgets);
      expect(find.text('Pending'), findsNothing);
      await unmount(tester);
    });

    testWidgets('documents: expiry text in Indonesian', (tester) async {
      final env = TestEnv(
        documents: FakeDocumentsRepository()
          ..items = [FakeDocumentsRepository.sample(documentJson())],
      );
      await inId(tester, env);
      await tester.tap(find.text('Profil').last);
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Dokumen'));
      await settle(tester, seconds: 1);
      expect(find.text('Berakhir dalam 12 hari'), findsOneWidget);
      expect(find.text('Expires in 12 days'), findsNothing);
      await unmount(tester);
    });

    testWidgets('error states are localized', (tester) async {
      final env = TestEnv(
        attendance: FakeAttendanceRepository()
          ..todayError = const NoConnectionException(),
      );
      await inId(tester, env);
      expect(find.text('Anda sedang offline'), findsOneWidget);
      expect(find.text('Coba lagi'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets(
      'login screen is localized before sign-in, with the language switch',
      (tester) async {
        final env = TestEnv(auth: FakeAuthRepository(signedIn: false));
        await inId(tester, env);
        expect(find.text('Selamat datang kembali'), findsOneWidget);
        expect(find.text('ID'), findsOneWidget);
        expect(find.text('EN'), findsOneWidget);
        await tester.tap(find.text('EN'));
        await settle(tester, seconds: 1);
        expect(find.text('Welcome back'), findsOneWidget);
        expect(
          containerOf(tester).read(authControllerProvider).isSignedIn,
          isFalse,
        );
        await unmount(tester);
      },
    );
  });
}
