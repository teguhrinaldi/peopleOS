import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';

import '../helpers/fakes.dart';
import '../helpers/harness.dart';
import '../helpers/sample_json.dart';

Future<void> startCheckIn(WidgetTester tester) async {
  await tester.tap(find.text('Check In'));
  await settle(tester, seconds: 1);
}

void main() {
  testWidgets('inside the work area: locate, confirm, success', (tester) async {
    final env = TestEnv();
    await pumpApp(tester, env);

    await startCheckIn(tester);

    expect(find.text('Confirm your location'), findsOneWidget);
    expect(find.text('You are within work area'), findsOneWidget);
    expect(find.textContaining('from Head Office'), findsWidgets);
    expect(find.text('Estimated on this device'), findsOneWidget);
    expect(find.text('Within area'), findsOneWidget);
    expect(env.location.calls, 1);

    await tester.tap(find.text('Confirm Check In'));
    await settle(tester, seconds: 1);

    expect(find.text('Checked in at 08:04'), findsOneWidget);
    expect(env.attendance.submitted, hasLength(1));
    final sent = env.attendance.submitted.single;
    expect(sent.type, CheckType.checkIn);
    expect(sent.latitude, isNotNull);
    expect(sent.isMockLocation, isFalse);
    expect(sent.isOfflineSync, isFalse);
    expect(sent.idempotencyKey.length, greaterThanOrEqualTo(8));
    await unmount(tester);
  });

  testWidgets('the OS mock-location flag is forwarded to the server', (
    tester,
  ) async {
    final env = TestEnv(
      location: FakeLocationService(
        fix: FakeLocationService.atOffice(mocked: true),
      ),
    );
    await pumpApp(tester, env);
    await startCheckIn(tester);
    await tester.tap(find.text('Confirm Check In'));
    await settle(tester, seconds: 1);

    expect(env.attendance.submitted.single.isMockLocation, isTrue);
    await unmount(tester);
  });

  testWidgets(
    'outside the work area: local hint, then the server error is surfaced',
    (tester) async {
      final env = TestEnv(
        location: FakeLocationService(
          fix: FakeLocationService.atOffice(dLat: 0.003),
        ),
      );
      env.attendance.submitHandler = (item) => throw const ValidationException(
        'Outside geofence',
        code: 'OUTSIDE_GEOFENCE',
        details: {'distance_meters': 334.2, 'radius_meters': 150},
      );
      await pumpApp(tester, env);
      await startCheckIn(tester);

      expect(
        find.text('You appear to be outside the work area'),
        findsOneWidget,
      );
      expect(find.text('Outside area'), findsOneWidget);
      expect(find.text('Estimated on this device'), findsOneWidget);
      expect(
        find.textContaining('The server makes the final decision'),
        findsOneWidget,
      );

      // The user can still try; the server (authority) refuses.
      await tester.tap(find.text('Confirm Check In'));
      await settle(tester, seconds: 1);

      expect(find.text("Couldn't record your location"), findsOneWidget);
      expect(
        find.text(
          'You are 334 m from the work site. You must be within 150 m to record attendance.',
        ),
        findsOneWidget,
      );
      expect(find.text('Try again'), findsOneWidget);
      expect(
        await env.outbox.all(),
        isEmpty,
        reason: 'a refused live action is not queued',
      );
      await unmount(tester);
    },
  );

  testWidgets('permission denied: guidance, then retry succeeds', (
    tester,
  ) async {
    final env = TestEnv(
      location: FakeLocationService(problem: LocationProblem.denied),
    );
    await pumpApp(tester, env);
    await startCheckIn(tester);

    expect(find.text('Location permission needed'), findsOneWidget);
    expect(
      find.textContaining('never tracks you in the background'),
      findsOneWidget,
    );
    expect(find.text('Confirm Check In'), findsNothing);

    env.location.problem = null;
    await tester.tap(find.text('Try again'));
    await settle(tester, seconds: 1);

    expect(find.text('You are within work area'), findsOneWidget);
    expect(env.location.calls, 2);
    await unmount(tester);
  });

  testWidgets('permanently denied: opens the app settings', (tester) async {
    final env = TestEnv(
      location: FakeLocationService(problem: LocationProblem.deniedForever),
    );
    await pumpApp(tester, env);
    await startCheckIn(tester);

    expect(find.text('Location is blocked'), findsOneWidget);
    await tester.tap(find.text('Open app settings'));
    await tester.pump();

    expect(env.location.openedAppSettings, 1);
    await unmount(tester);
  });

  testWidgets('location services off: opens the location settings', (
    tester,
  ) async {
    final env = TestEnv(
      location: FakeLocationService(problem: LocationProblem.serviceDisabled),
    );
    await pumpApp(tester, env);
    await startCheckIn(tester);

    expect(find.text('Location services are off'), findsOneWidget);
    await tester.tap(find.text('Open location settings'));
    await tester.pump();

    expect(env.location.openedLocationSettings, 1);
    await unmount(tester);
  });

  testWidgets('no GPS fix: try again message', (tester) async {
    final env = TestEnv(
      location: FakeLocationService(problem: LocationProblem.unavailable),
    );
    await pumpApp(tester, env);
    await startCheckIn(tester);

    expect(find.text("Couldn't get your location"), findsOneWidget);
    await unmount(tester);
  });

  group('selfie policy', () {
    TestEnv selfieEnv({SelfiePhoto? photo}) => TestEnv(
      attendance: FakeAttendanceRepository(today: todayJson(selfie: true)),
      selfie: FakeSelfieService(photo: photo ?? SelfiePhoto(tinyPng)),
    );

    testWidgets('confirm stays disabled until a selfie is accepted', (
      tester,
    ) async {
      final env = selfieEnv();
      await pumpApp(tester, env);
      await startCheckIn(tester);

      expect(find.text('Selfie required'), findsOneWidget);
      expect(find.text('Take a selfie to continue.'), findsOneWidget);
      await tester.tap(find.text('Confirm Check In'));
      await settle(tester, seconds: 1);
      expect(env.attendance.submitted, isEmpty);

      // Camera -> preview -> Use photo
      await tester.tap(find.text('Take'));
      await settle(tester, seconds: 1);
      expect(find.text('Selfie preview'), findsOneWidget);
      expect(find.text('Retake'), findsOneWidget);
      await tester.tap(find.text('Use photo'));
      await settle(tester, seconds: 1);

      expect(find.text('Selfie ready'), findsOneWidget);
      await tester.tap(find.text('Confirm Check In'));
      await settle(tester, seconds: 1);

      expect(env.attendance.submitted, hasLength(1));
      expect(env.attendance.selfies.single, isNotNull);
      expect(find.textContaining('Checked in at'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('retake asks the camera again', (tester) async {
      final env = selfieEnv();
      await pumpApp(tester, env);
      await startCheckIn(tester);

      await tester.tap(find.text('Take'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Retake'));
      await settle(tester, seconds: 1);

      expect(env.selfie.calls, 2);
      expect(find.text('Selfie preview'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('cancelling the camera keeps the flow intact', (tester) async {
      final env = TestEnv(
        attendance: FakeAttendanceRepository(today: todayJson(selfie: true)),
        selfie: FakeSelfieService(photo: null),
      );
      await pumpApp(tester, env);
      await startCheckIn(tester);

      await tester.tap(find.text('Take'));
      await settle(tester, seconds: 1);

      expect(find.text('Selfie required'), findsOneWidget);
      expect(env.attendance.submitted, isEmpty);
      await unmount(tester);
    });

    testWidgets('server SELFIE_REQUIRED is explained', (tester) async {
      final env = selfieEnv();
      env.attendance.submitHandler = (item) => throw const ValidationException(
        'Selfie required',
        code: 'SELFIE_REQUIRED',
      );
      await pumpApp(tester, env);
      await startCheckIn(tester);
      await tester.tap(find.text('Take'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Use photo'));
      await settle(tester, seconds: 1);
      await tester.tap(find.text('Confirm Check In'));
      await settle(tester, seconds: 1);

      expect(find.text('A selfie is required for this check.'), findsOneWidget);
      await unmount(tester);
    });
  });

  testWidgets('offline: saved on the device, Home shows a pending state', (
    tester,
  ) async {
    final env = TestEnv(connectivity: FakeConnectivity(online: false));
    await pumpApp(tester, env);
    await startCheckIn(tester);

    expect(
      find.textContaining("You're offline. This check will be saved"),
      findsOneWidget,
    );
    await tester.tap(find.text('Confirm Check In'));
    await settle(tester, seconds: 1);

    expect(find.text('Saved on this device'), findsOneWidget);
    expect(find.textContaining('pending sync'), findsOneWidget);
    expect(env.attendance.submitted, isEmpty);
    final queued = await env.outbox.all();
    expect(queued, hasLength(1));
    expect(queued.single.syncState, SyncState.pendingSync);

    await tester.tap(find.text('Done'));
    await settle(tester, seconds: 1);

    expect(find.text('Saved on this device. Waiting to sync.'), findsOneWidget);
    expect(
      find.text('Check Out'),
      findsOneWidget,
      reason: 'optimistic local state',
    );
    await unmount(tester);
  });

  testWidgets('a timeout on submit also ends in "saved on this device"', (
    tester,
  ) async {
    final env = TestEnv();
    env.attendance.submitHandler = (item) =>
        throw const RequestTimeoutException();
    await pumpApp(tester, env);
    await startCheckIn(tester);
    await tester.tap(find.text('Confirm Check In'));
    await settle(tester, seconds: 1);

    expect(find.text('Saved on this device'), findsOneWidget);
    final queued = await env.outbox.all();
    expect(
      queued.single.idempotencyKey,
      env.attendance.submitted.single.idempotencyKey,
    );
    await unmount(tester);
  });

  testWidgets('check-out flow uses the same screen with its own copy', (
    tester,
  ) async {
    final env = TestEnv(
      attendance: FakeAttendanceRepository(
        today: todayJson(state: 'CHECKED_IN'),
      ),
    );
    await pumpApp(tester, env);

    await tester.tap(find.text('Check Out'));
    await settle(tester, seconds: 1);
    expect(find.text('Confirm check-out'), findsOneWidget);

    await tester.tap(find.text('Confirm Check Out'));
    await settle(tester, seconds: 1);

    expect(env.attendance.submitted.single.type, CheckType.checkOut);
    expect(find.textContaining('Checked out at'), findsOneWidget);
    await unmount(tester);
  });
}
