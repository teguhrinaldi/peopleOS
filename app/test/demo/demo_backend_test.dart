import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/utils/geo.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/data/demo_attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/demo/demo_data.dart';
import 'package:peopleos/features/requests/data/demo_requests_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/fakes.dart';

void main() {
  late SharedPreferences prefs;
  late AppSettings settings;
  late DemoBackend backend;
  late DemoAttendanceRepository attendance;
  late DemoRequestsRepository requests;
  var clock = DateTime(2026, 9, 30, 8, 5);

  OutboxItem item({
    String key = 'k-12345678',
    CheckType type = CheckType.checkIn,
    double? lat,
    double? lng,
    double accuracy = 10,
    bool mocked = false,
  }) => OutboxItem(
    idempotencyKey: key,
    employeeId: 12,
    type: type,
    latitude: lat ?? DemoData.siteLat + 0.0003,
    longitude: lng ?? DemoData.siteLng,
    accuracyMeters: accuracy,
    locationCapturedAt: clock.toUtc(),
    deviceCapturedAt: clock.toUtc(),
    createdAt: clock.toUtc(),
    isMockLocation: mocked,
  );

  Future<void> boot() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    settings = AppSettings(prefs);
    clock = DateTime(2026, 9, 30, 8, 5);
    backend = DemoBackend(
      prefs: prefs,
      settings: settings,
      clock: () => clock,
      latency: Duration.zero,
    );
    attendance = DemoAttendanceRepository(backend);
    requests = DemoRequestsRepository(backend);
  }

  setUp(boot);

  group('geofence maths', () {
    test('haversine matches known distances', () {
      expect(haversineMeters(0, 0, 0, 0), 0);
      // 0.001 degree of latitude is ~111.2 m.
      expect(
        haversineMeters(-6.9218, 107.6071, -6.9208, 107.6071),
        closeTo(111.2, 0.6),
      );
      // Bandung -> Jakarta is roughly 115 km.
      expect(
        haversineMeters(-6.9218, 107.6071, -6.2088, 106.8456) / 1000,
        closeTo(112, 6),
      );
    });

    test(
      'inside the radius checks in and reports the server distance',
      () async {
        final r = await attendance.submit(item());
        expect(r.attendance.status, 'CHECKED_IN');
        expect(r.distanceMeters, closeTo(33.4, 1));
        expect(r.insideGeofence, isTrue);
      },
    );

    test(
      'outside the radius is OUTSIDE_GEOFENCE with distance + radius',
      () async {
        final far = item(lat: DemoData.siteLat + 0.003);
        await expectLater(
          attendance.submit(far),
          throwsA(
            isA<ValidationException>()
                .having((e) => e.code, 'code', 'OUTSIDE_GEOFENCE')
                .having((e) => e.details['radius_meters'], 'radius', 150.0)
                .having(
                  (e) => e.details['distance_meters'],
                  'distance',
                  greaterThan(300),
                ),
          ),
        );
      },
    );

    test('boundary: just inside 150 m passes, just outside fails', () async {
      // 0.00134 deg lat ~ 149 m ; 0.00136 ~ 151 m
      await attendance.submit(
        item(key: 'in-12345678', lat: DemoData.siteLat + 0.00134),
      );
      final t = await attendance.today();
      expect(t.state, TodayState.checkedIn);
      await backend.reset();
      await expectLater(
        attendance.submit(
          item(key: 'out-1234567', lat: DemoData.siteLat + 0.00137),
        ),
        throwsA(isA<ValidationException>()),
      );
    });
  });

  group('server rules', () {
    test(
      'idempotent replay returns the same attendance with replayed=true',
      () async {
        final first = await attendance.submit(item());
        final again = await attendance.submit(item());
        expect(first.replayed, isFalse);
        expect(again.replayed, isTrue);
        expect(again.attendance.id, first.attendance.id);
      },
    );

    test(
      'state conflicts: double check-in, check-out first, double check-out',
      () async {
        await expectLater(
          attendance.submit(item(type: CheckType.checkOut, key: 'o-1234567')),
          throwsA(
            isA<ConflictException>().having(
              (e) => e.code,
              'code',
              'NOT_CHECKED_IN',
            ),
          ),
        );
        await attendance.submit(item());
        await expectLater(
          attendance.submit(item(key: 'k2-1234567')),
          throwsA(
            isA<ConflictException>().having(
              (e) => e.code,
              'code',
              'ALREADY_CHECKED_IN',
            ),
          ),
        );
        clock = DateTime(2026, 9, 30, 17, 10);
        final out = await attendance.submit(
          item(type: CheckType.checkOut, key: 'o2-123456'),
        );
        expect(out.attendance.status, 'CHECKED_OUT');
        expect(out.attendance.workedMinutes, greaterThan(400));
        await expectLater(
          attendance.submit(item(type: CheckType.checkOut, key: 'o3-123456')),
          throwsA(
            isA<ConflictException>().having(
              (e) => e.code,
              'code',
              'ALREADY_CHECKED_OUT',
            ),
          ),
        );
      },
    );

    test('lateness: after the 10 minute grace the check-in is late', () async {
      clock = DateTime(2026, 9, 30, 8, 22);
      final r = await attendance.submit(item());
      expect(r.attendance.isLate, isTrue);
      expect(r.attendance.lateMinutes, 22);
    });

    test('GPS rules: missing, mocked, inaccurate', () async {
      await expectLater(
        attendance.submit(
          OutboxItem(
            idempotencyKey: 'no-gps-1234',
            employeeId: 12,
            type: CheckType.checkIn,
            deviceCapturedAt: clock.toUtc(),
            createdAt: clock.toUtc(),
          ),
        ),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'GPS_REQUIRED',
          ),
        ),
      );
      await expectLater(
        attendance.submit(item(mocked: true, key: 'mock-12345')),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'MOCK_LOCATION_DETECTED',
          ),
        ),
      );
      await expectLater(
        attendance.submit(item(accuracy: 400, key: 'acc-123456')),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'GPS_ACCURACY_TOO_LOW',
          ),
        ),
      );
    });

    test('selfie policy scenario requires a selfie', () async {
      await settings.setDemoScenario(DemoScenario.selfieRequired);
      final today = await attendance.today();
      expect(today.schedule!.policy.requireSelfie, isTrue);
      await expectLater(
        attendance.submit(item()),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'SELFIE_REQUIRED',
          ),
        ),
      );
      final ok = await attendance.submit(
        item(key: 'self-12345'),
        selfie: tinyPng,
      );
      expect(ok.attendance.checkIn!.hasSelfie, isTrue);
    });

    test('offline replay older than the policy window is refused', () async {
      final old = item().copyWith(isOfflineSync: true);
      clock = DateTime(2026, 9, 30, 22, 0); // 13h50m later
      await expectLater(
        attendance.submit(old),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'OFFLINE_EVENT_TOO_OLD',
          ),
        ),
      );
    });

    test('offline replay inside the window keeps the device time', () async {
      final queued = item().copyWith(isOfflineSync: true);
      clock = DateTime(2026, 9, 30, 9, 30);
      final r = await attendance.submit(queued);
      expect(r.attendance.checkInTime, '08:05');
      expect(r.attendance.checkIn!.isOfflineSync, isTrue);
    });

    test('scenarios block attendance like the real API', () async {
      await settings.setDemoScenario(DemoScenario.terminated);
      expect((await attendance.today()).state, TodayState.blocked);
      await expectLater(
        attendance.submit(item()),
        throwsA(
          isA<ForbiddenException>().having(
            (e) => e.code,
            'code',
            'EMPLOYEE_TERMINATED',
          ),
        ),
      );
      await settings.setDemoScenario(DemoScenario.restDay);
      expect((await attendance.today()).state, TodayState.notWorkingDay);
      await settings.setDemoScenario(DemoScenario.noSchedule);
      final t = await attendance.today();
      expect(t.state, TodayState.noSchedule);
      expect(t.schedule, isNull);
    });

    test(
      'simulated offline: reads work, writes fail with NoConnectionException',
      () async {
        await settings.setDemoOffline(true);
        expect((await attendance.today()).state, TodayState.notCheckedIn);
        await expectLater(
          attendance.submit(item()),
          throwsA(isA<NoConnectionException>()),
        );
      },
    );
  });

  group('history', () {
    test('30 days of realistic, deterministic history', () async {
      final list = await attendance.history(
        from: DateTime(2026, 9),
        to: DateTime(2026, 9, 30),
      );
      expect(list.length, greaterThanOrEqualTo(15));
      expect(list.every((r) => r.workDate.weekday <= 5), isTrue);
      expect(list.every((r) => r.checkInTime != null), isTrue);
      expect(list.any((r) => r.isLate), isTrue);
      expect(list.any((r) => !r.isLate), isTrue);
      // Newest first.
      for (var i = 1; i < list.length; i++) {
        expect(list[i - 1].workDate.isAfter(list[i].workDate), isTrue);
      }
    });

    test('the record created today shows up in history', () async {
      await attendance.submit(item());
      final list = await attendance.history(
        from: DateTime(2026, 9, 30),
        to: DateTime(2026, 9, 30),
      );
      expect(list.single.status, 'CHECKED_IN');
    });

    test('state persists across backend instances (restart)', () async {
      await attendance.submit(item());
      final again = DemoAttendanceRepository(
        DemoBackend(
          prefs: prefs,
          settings: settings,
          clock: () => clock,
          latency: Duration.zero,
        ),
      );
      expect((await again.today()).state, TodayState.checkedIn);
    });
  });

  group('corrections and overtime', () {
    ZonedTime at(DateTime d, int h, int m) =>
        ZonedTime.fromWall(d, h, m, clock.timeZoneOffset);

    test(
      'create -> pending -> duplicate 409 -> cancel -> not pending 409',
      () async {
        final date = DateTime(2026, 9, 28);
        final c = await requests.createCorrection(
          workDate: date,
          checkOut: at(date, 17, 5),
          reason: 'Forgot to check out',
        );
        expect(c.status.wire, 'PENDING');

        await expectLater(
          requests.createCorrection(
            workDate: date,
            checkOut: at(date, 17, 10),
            reason: 'Second try here',
          ),
          throwsA(
            isA<ConflictException>().having(
              (e) => e.code,
              'code',
              'CORRECTION_ALREADY_PENDING',
            ),
          ),
        );

        final cancelled = await requests.cancelCorrection(c.id);
        expect(cancelled.status.wire, 'CANCELLED');
        await expectLater(
          requests.cancelCorrection(c.id),
          throwsA(
            isA<ConflictException>().having(
              (e) => e.code,
              'code',
              'REQUEST_NOT_PENDING',
            ),
          ),
        );
      },
    );

    test(
      'validation: needs a time and a reason; 30-day window; no future',
      () async {
        final date = DateTime(2026, 9, 28);
        await expectLater(
          requests.createCorrection(workDate: date, reason: ''),
          throwsA(
            isA<ValidationException>().having(
              (e) => e.fieldErrors.keys,
              'fields',
              containsAll(['reason', 'requested_check_in_at']),
            ),
          ),
        );
        await expectLater(
          requests.createCorrection(
            workDate: DateTime(2026, 8, 1),
            checkIn: at(DateTime(2026, 8, 1), 8, 0),
            reason: 'Too old to correct',
          ),
          throwsA(
            isA<ValidationException>().having(
              (e) => e.code,
              'code',
              'CORRECTION_WINDOW_EXPIRED',
            ),
          ),
        );
        await expectLater(
          requests.createCorrection(
            workDate: DateTime(2026, 10, 2),
            checkIn: at(DateTime(2026, 10, 2), 8, 0),
            reason: 'In the future',
          ),
          throwsA(
            isA<ValidationException>().having(
              (e) => e.code,
              'code',
              'INVALID_WORK_DATE',
            ),
          ),
        );
      },
    );

    test('overtime: end after start, max length, overlap', () async {
      final d = DateTime(2026, 9, 30);
      await expectLater(
        requests.createOvertime(
          workDate: d,
          start: at(d, 19, 0),
          end: at(d, 18, 0),
          reason: 'Backwards range',
        ),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.fieldErrors.keys,
            'fields',
            contains('end_at'),
          ),
        ),
      );
      await expectLater(
        requests.createOvertime(
          workDate: d,
          start: at(d, 17, 0),
          end: at(d, 23, 0),
          reason: 'Way too long',
        ),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.code,
            'code',
            'OVERTIME_TOO_LONG',
          ),
        ),
      );
      final ok = await requests.createOvertime(
        workDate: d,
        start: at(d, 17, 0),
        end: at(d, 19, 0),
        reason: 'Release preparation',
      );
      expect(ok.requestedMinutes, 120);
      await expectLater(
        requests.createOvertime(
          workDate: d,
          start: at(d, 18, 0),
          end: at(d, 20, 0),
          reason: 'Overlapping window',
        ),
        throwsA(
          isA<ConflictException>().having(
            (e) => e.code,
            'code',
            'OVERTIME_OVERLAP',
          ),
        ),
      );
    });

    test('seeded data has an approved and a rejected correction', () async {
      final list = await requests.corrections();
      expect(
        list.map((c) => c.status.wire),
        containsAll(['APPROVED', 'REJECTED']),
      );
    });
  });

  test('demo login accepts the demo account only', () async {
    await expectLater(
      backend.login('employee@peopleos.test', 'nope'),
      throwsA(
        isA<UnauthorizedException>().having(
          (e) => e.isBadCredentials,
          'bad',
          true,
        ),
      ),
    );
    final session = await backend.login('EMPLOYEE@peopleos.test', 'password');
    expect(
      JsonReader.of(session).obj('employee')!.str('full_name'),
      'Michael Carter',
    );
  });
}
