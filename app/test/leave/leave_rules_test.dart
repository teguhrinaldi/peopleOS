import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/demo/demo_backend.dart';
import 'package:peopleos/features/demo/demo_leave.dart';
import 'package:peopleos/features/leave/data/demo_leave_repository.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/notifications/data/demo_notifications_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Wednesday 7 Oct 2026 is "today" for the pure rule tests.
final today = DateTime(2026, 10, 7);

DemoLeaveBook book([List<Map<String, dynamic>>? requests]) {
  var id = 100;
  return DemoLeaveBook(
    requests: requests ?? [],
    today: today,
    now: ZonedTime(DateTime.utc(2026, 10, 7, 2), const Duration(hours: 7)),
    nextId: () => id++,
  );
}

Map<String, dynamic> body(
  int type,
  String start, [
  String? end,
  String? half,
  String? reason = 'Family matters',
]) => {
  'leave_type_id': type,
  'start_date': start,
  'end_date': end ?? start,
  'half_day': ?half,
  'reason': reason,
};

Matcher code(String c) =>
    throwsA(isA<AppException>().having((e) => e.code, 'code', c));

void main() {
  group('working days', () {
    test('weekends are free: Friday to Monday is 2 days', () {
      final dates = demoWorkingDates(
        DateTime(2026, 10, 16),
        DateTime(2026, 10, 19),
      );
      expect(dates, [DateTime(2026, 10, 16), DateTime(2026, 10, 19)]);
    });

    test('public holidays inside a request are free', () {
      // 24 Dec (Thu) .. 28 Dec (Mon): 25 Dec holiday, 26/27 weekend.
      final dates = demoWorkingDates(
        DateTime(2026, 12, 24),
        DateTime(2026, 12, 28),
      );
      expect(dates.length, 2);
    });

    test('preview counts working days and balance after', () {
      final p = LeavePreview.fromJson(
        JsonReader(book().preview(body(1, '2026-11-05', '2026-11-10'))),
      );
      expect(p.days, 4);
      expect(p.workingDates.first, DateTime(2026, 11, 5));
      expect(p.balance!.available, 12);
      expect(p.availableAfter, 8);
    });

    test('a half day counts 0.5', () {
      final p = book().preview(body(1, '2026-11-05', null, 'AM'));
      expect(p['days'], '0.5');
    });
  });

  group('validation', () {
    test('half day must be a single date', () {
      expect(
        () => book().validate(
          body(1, '2026-11-05', '2026-11-06', 'PM'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('HALF_DAY_SINGLE_DATE'),
      );
    });

    test('end before start', () {
      expect(
        () => book().validate(
          body(1, '2026-11-06', '2026-11-05'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('INVALID_DATE_RANGE'),
      );
    });

    test('annual leave needs 3 days notice, drafts are not checked', () {
      expect(
        () => book().validate(
          body(1, '2026-10-08'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('LEAVE_NOTICE_TOO_SHORT'),
      );
      expect(
        book().validate(
          body(1, '2026-10-08'),
          forSubmit: false,
          checkBookings: false,
        ),
        isNotEmpty,
      );
    });

    test('sick leave may start up to 7 days in the past, not 8', () {
      expect(
        book().validate(
          body(2, '2026-09-30'),
          forSubmit: true,
          checkBookings: true,
        ),
        isNotEmpty,
      );
      expect(
        () => book().validate(
          body(2, '2026-09-29'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('LEAVE_TOO_FAR_IN_PAST'),
      );
    });

    test('a request cannot span two leave years', () {
      expect(
        () => book().validate(
          body(1, '2026-12-30', '2027-01-05'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('LEAVE_SPANS_YEARS'),
      );
    });

    test('weekend only is LEAVE_NO_WORKING_DAYS', () {
      expect(
        () => book().validate(
          body(1, '2026-11-07', '2026-11-08'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('LEAVE_NO_WORKING_DAYS'),
      );
    });

    test('personal leave is limited to 2 working days per request', () {
      expect(
        () => book().validate(
          body(3, '2026-11-09', '2026-11-11'),
          forSubmit: true,
          checkBookings: true,
        ),
        code('LEAVE_TOO_LONG'),
      );
    });

    test('reason required for sick leave on submit', () {
      expect(
        () => book().validate(
          body(2, '2026-10-07', null, null, ''),
          forSubmit: true,
          checkBookings: true,
        ),
        throwsA(
          isA<ValidationException>().having(
            (e) => e.fieldErrors.keys,
            'fields',
            contains('reason'),
          ),
        ),
      );
    });
  });

  group('bookings and balance', () {
    test('overlap with a live request; opposite halves of a day are fine', () {
      final b = book();
      b.create(body(1, '2026-11-05', '2026-11-06'), submit: true);
      expect(
        () => b.create(body(1, '2026-11-06', '2026-11-09'), submit: true),
        code('LEAVE_OVERLAP'),
      );
      b.create(body(1, '2026-11-12', null, 'AM'), submit: true);
      expect(
        b.create(body(1, '2026-11-12', null, 'PM'), submit: true)['days'],
        '0.5',
      );
      expect(
        () => b.create(body(1, '2026-11-12', null, 'AM'), submit: true),
        code('LEAVE_OVERLAP'),
      );
    });

    test('insufficient balance reports available and requested', () {
      final b = book();
      expect(
        () => b.create(body(1, '2026-11-02', '2026-11-20'), submit: true),
        throwsA(
          isA<ValidationException>()
              .having((e) => e.code, 'code', 'LEAVE_INSUFFICIENT_BALANCE')
              .having((e) => e.details['available'], 'available', '12.0')
              .having((e) => e.details['requested'], 'requested', '15.0'),
        ),
      );
    });

    test('submit reserves, approve uses, cancel releases', () {
      final b = book();
      final r = b.create(body(1, '2026-11-05', '2026-11-06'), submit: true);
      var bal = b.balance(1, 2026);
      expect(bal['reserved'], '2.0');
      expect(bal['available'], '10.0');

      r['status'] = 'APPROVED'; // the manager approves (web admin)
      bal = b.balance(1, 2026);
      expect(bal['used'], '2.0');
      expect(bal['reserved'], '0.0');
      expect(bal['available'], '10.0');

      b.cancel(r['id'] as int, 'Plans changed');
      bal = b.balance(1, 2026);
      expect(bal['used'], '0.0');
      expect(bal['available'], '12.0');
    });

    test('unlimited types track usage only', () {
      final b = book();
      b.create(body(2, '2026-10-07'), submit: true);
      final bal = b.balance(2, 2026);
      expect(bal['unlimited'], true);
      expect(bal['available'], isNull);
      expect(bal['reserved'], '1.0');
    });

    test('drafts hold no balance; submit runs the full checks', () {
      final b = book();
      final d = b.create(body(1, '2026-11-05', '2026-11-06'), submit: false);
      expect(d['status'], 'DRAFT');
      expect(b.balance(1, 2026)['reserved'], '0.0');
      b.update(d['id'] as int, body(1, '2026-11-05', '2026-11-09'));
      expect(b.find(d['id'] as int)['days'], '3.0');
      b.submit(d['id'] as int);
      expect(b.find(d['id'] as int)['status'], 'PENDING');
      expect(b.balance(1, 2026)['reserved'], '3.0');
      expect(
        () => b.update(d['id'] as int, body(1, '2026-11-05')),
        code('REQUEST_NOT_PENDING'),
      );
    });
  });

  group('state machine', () {
    test('approved leave can be cancelled only before it starts', () {
      final b = book();
      final past = b.create(body(2, '2026-10-05'), submit: true)
        ..['status'] = 'APPROVED';
      expect(
        () => b.cancel(past['id'] as int, null),
        code('LEAVE_ALREADY_STARTED'),
      );
      final future = b.create(body(1, '2026-11-05'), submit: true)
        ..['status'] = 'APPROVED';
      expect(b.cancel(future['id'] as int, null)['status'], 'CANCELLED');
    });

    test('decided requests cannot be cancelled again', () {
      final b = book();
      final r = b.create(body(1, '2026-11-05'), submit: true)
        ..['status'] = 'REJECTED';
      expect(() => b.cancel(r['id'] as int, null), code('REQUEST_NOT_PENDING'));
    });
  });

  group('demo backend integration', () {
    late DemoBackend backend;
    late AppSettings settings;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      settings = AppSettings(prefs);
      backend = DemoBackend(
        prefs: prefs,
        settings: settings,
        clock: () => DateTime(2026, 10, 7, 9),
        latency: Duration.zero,
      );
    });

    test(
      'seed matches the API demo: Annual 12 with 3 used and 3 reserved',
      () async {
        final repo = DemoLeaveRepository(backend);
        final annual = (await repo.balances()).firstWhere(
          (b) => b.type.code == 'ANNUAL',
        );
        expect(annual.entitlement, 12);
        expect(annual.used, 3);
        expect(annual.reserved, 3);
        expect(annual.available, 6);
        final types = await repo.types();
        expect(types.map((t) => t.ref.code), [
          'ANNUAL',
          'SICK',
          'PERSONAL',
          'UNPAID',
          'OTHER',
        ]);
        final requests = await repo.requests();
        expect(
          requests.map((r) => r.status),
          containsAll([
            LeaveStatus.approved,
            LeaveStatus.pending,
            LeaveStatus.rejected,
          ]),
        );
      },
    );

    test(
      'approved leave today: attendance is ON_LEAVE and check-in is refused',
      () async {
        await settings.setDemoScenario(DemoScenario.onLeave);
        final today = TodayAttendance.fromJson(
          JsonReader(await backend.today()),
        );
        expect(today.state, TodayState.onLeave);
        expect(today.canCheckIn, isFalse);
        await expectLater(
          backend.submit(
            OutboxItem(
              idempotencyKey: 'leave-day-1',
              employeeId: 12,
              type: CheckType.checkIn,
              latitude: -6.9218,
              longitude: 107.6071,
              accuracyMeters: 5,
              deviceCapturedAt: DateTime.utc(2026, 10, 7, 2),
              createdAt: DateTime.utc(2026, 10, 7, 2),
            ),
            null,
          ),
          code('ON_LEAVE'),
        );
      },
    );

    test('notifications: unread count, mark one, mark all', () async {
      final repo = DemoNotificationsRepository(backend);
      final page = await repo.list();
      expect(page.items, isNotEmpty);
      final initial = page.unreadCount;
      expect(initial, greaterThanOrEqualTo(2));
      expect(await repo.unreadCount(), initial);
      final unread = page.items.firstWhere((n) => !n.isRead);
      await repo.markRead(unread.id);
      expect(await repo.unreadCount(), initial - 1);
      expect(await repo.markAllRead(), initial - 1);
      expect(await repo.unreadCount(), 0);
      expect(page.items.first.createdAt, isNotNull);
    });
  });
}
