import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/app/app.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/features/attendance/data/outbox_store.dart';
import 'package:peopleos/features/attendance/domain/attendance_repository.dart';
import 'package:peopleos/features/attendance/data/selfie_store.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'batch2_fakes.dart';
import 'fakes.dart';

/// Everything a widget test may want to poke at.
class TestEnv {
  TestEnv({
    FakeAuthRepository? auth,
    FakeAttendanceRepository? attendance,
    FakeRequestsRepository? requests,
    FakeLocationService? location,
    FakeSelfieService? selfie,
    FakeConnectivity? connectivity,
    MemoryOutboxStore? outbox,
    MemorySelfieStore? selfieStore,
    FakeLeaveRepository? leave,
    FakeNotificationsRepository? notifications,
    FakePayslipRepository? payslips,
    FakeDocumentsRepository? documents,
    FakeFileExporter? exporter,
    this.attendanceOverride,
  }) : auth = auth ?? FakeAuthRepository(signedIn: true),
       attendance = attendance ?? FakeAttendanceRepository(),
       requests = requests ?? FakeRequestsRepository(),
       location = location ?? FakeLocationService(),
       selfie = selfie ?? FakeSelfieService(),
       connectivity = connectivity ?? FakeConnectivity(),
       outbox = outbox ?? MemoryOutboxStore(),
       selfieStore = selfieStore ?? MemorySelfieStore(),
       leave = leave ?? FakeLeaveRepository(),
       notifications = notifications ?? FakeNotificationsRepository(),
       payslips = payslips ?? FakePayslipRepository(),
       documents = documents ?? FakeDocumentsRepository(),
       exporter = exporter ?? FakeFileExporter();

  final FakeAuthRepository auth;
  final FakeAttendanceRepository attendance;
  final FakeRequestsRepository requests;
  final FakeLocationService location;
  final FakeSelfieService selfie;
  final FakeConnectivity connectivity;
  final MemoryOutboxStore outbox;
  final MemorySelfieStore selfieStore;
  final FakeLeaveRepository leave;
  final FakeNotificationsRepository notifications;
  final FakePayslipRepository payslips;
  final FakeDocumentsRepository documents;
  final FakeFileExporter exporter;

  /// Replaces the fake attendance repository (e.g. with a real API one).
  final AttendanceRepository? attendanceOverride;
}

/// Boots the real app (router, theme, controllers) on top of fakes and waits
/// for the splash to hand over to Login or Home.
Future<void> pumpApp(
  WidgetTester tester,
  TestEnv env, {
  Size size = const Size(390, 844),
  List<Override> extraOverrides = const [],
  Map<String, Object> initialPrefs = const {},
}) async {
  // Decoded images are cached globally; a disposed cached image would render
  // as an error widget in the next test.
  imageCache.clear();
  imageCache.clearLiveImages();
  SharedPreferences.setMockInitialValues(initialPrefs);
  final prefs = await SharedPreferences.getInstance();
  tester.view.physicalSize = size * 2;
  tester.view.devicePixelRatio = 2;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    ProviderScope(
      retry: (count, error) => null,
      overrides: [
        sharedPreferencesProvider.overrideWithValue(prefs),
        tokenStoreProvider.overrideWithValue(MemoryTokenStore()),
        authRepositoryProvider.overrideWithValue(env.auth),
        attendanceRepositoryProvider.overrideWithValue(
          env.attendanceOverride ?? env.attendance,
        ),
        requestsRepositoryProvider.overrideWithValue(env.requests),
        locationServiceProvider.overrideWithValue(env.location),
        selfieServiceProvider.overrideWithValue(env.selfie),
        connectivityServiceProvider.overrideWithValue(env.connectivity),
        realConnectivityProvider.overrideWithValue(env.connectivity),
        outboxStoreProvider.overrideWithValue(env.outbox),
        selfieStoreProvider.overrideWithValue(env.selfieStore),
        appVersionProvider.overrideWithValue('1.0.0'),
        ...extraOverrides,
        leaveRepositoryProvider.overrideWithValue(env.leave),
        payslipRepositoryProvider.overrideWithValue(env.payslips),
        documentsRepositoryProvider.overrideWithValue(env.documents),
        fileExporterProvider.overrideWithValue(env.exporter),
        notificationsRepositoryProvider.overrideWithValue(env.notifications),
      ],
      child: const PeopleOsApp(),
    ),
  );
  await settle(tester);
}

/// Pumps long enough for splash (900 ms), navigation and async loads.
Future<void> settle(WidgetTester tester, {int seconds = 2}) async {
  await tester.pump();
  await tester.pump(Duration(seconds: seconds));
  await tester.pump(const Duration(milliseconds: 300));
}

/// Removes the app so timers and controllers are disposed before the
/// framework's end-of-test checks.
Future<void> unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(const Duration(milliseconds: 50));
}
