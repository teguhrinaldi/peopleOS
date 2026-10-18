import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/data/api_attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/sample_json.dart';

void main() {
  test('login payload -> Session (docs/api.md shape)', () {
    final s = Session.fromJson(loginJson());
    expect(s.userName, 'Michael Carter');
    expect(s.company.timezone, 'Asia/Jakarta');
    expect(s.employee.employeeNumber, 'EMP-0012');
    expect(s.employee.department!.name, 'Marketing');
    expect(s.employee.manager!.name, 'Amanda Clark');
    expect(s.employee.shift!.label, '08:00 - 17:00');
    expect(s.employee.joinDate, DateTime(2022, 1, 15));
    expect(s.permissions, contains('attendance.self'));
  });

  test('GET /attendance/today: NOT_CHECKED_IN', () {
    final t = TodayAttendance.fromJson(JsonReader(todayJson()));
    expect(t.state, TodayState.notCheckedIn);
    expect(t.canCheckIn, isTrue);
    expect(t.canCheckOut, isFalse);
    expect(t.serverTime.hhmm, '14:35');
    expect(t.serverTime.offset, const Duration(hours: 7));
    final s = t.schedule!;
    expect(s.shiftName, 'Regular Shift');
    expect(s.timeRange, '08:00 - 17:00');
    expect(s.location!.radiusMeters, 150);
    expect(s.policy.requireSelfie, isFalse);
    expect(s.policy.geofenceMode, 'enforce');
    expect(t.attendance, isNull);
  });

  test('GET /attendance/today: CHECKED_IN carries the attendance object', () {
    final t = TodayAttendance.fromJson(
      JsonReader(todayJson(state: 'CHECKED_IN', selfie: true)),
    );
    expect(t.state, TodayState.checkedIn);
    expect(t.elapsedMinutes, 391);
    expect(t.schedule!.policy.requireSelfie, isTrue);
    final a = t.attendance!;
    expect(a.checkInTime, '08:04');
    expect(a.checkIn!.distanceMeters, 15.67);
    expect(a.checkIn!.insideGeofence, isTrue);
    expect(a.checkOut, isNull);
    expect(a.isOpen, isTrue);
  });

  test(
    'every documented today.state maps, unknown falls back to NO_SCHEDULE',
    () {
      const states = {
        'NOT_CHECKED_IN': TodayState.notCheckedIn,
        'CHECKED_IN': TodayState.checkedIn,
        'CHECKED_OUT': TodayState.checkedOut,
        'PENDING_REVIEW_OPEN': TodayState.pendingReviewOpen,
        'PENDING_REVIEW': TodayState.pendingReview,
        'CORRECTION_PENDING': TodayState.correctionPending,
        'REJECTED': TodayState.rejected,
        'NOT_WORKING_DAY': TodayState.notWorkingDay,
        'NO_SCHEDULE': TodayState.noSchedule,
        'BLOCKED': TodayState.blocked,
      };
      states.forEach(
        (wire, expected) => expect(TodayState.parse(wire), expected),
      );
      expect(TodayState.parse('SOMETHING_NEW'), TodayState.noSchedule);
    },
  );

  test('BLOCKED today with null schedule parses', () {
    final t = TodayAttendance.fromJson(
      JsonReader(
        todayJson(
          state: 'BLOCKED',
          blockedReason: 'EMPLOYEE_TERMINATED',
          schedule: false,
        ),
      ),
    );
    expect(t.state, TodayState.blocked);
    expect(t.blockedReason, 'EMPLOYEE_TERMINATED');
    expect(t.schedule, isNull);
  });

  test('check-in response -> CheckResult', () {
    final r = CheckResult.fromJson(JsonReader(checkResultJson(replayed: true)));
    expect(r.replayed, isTrue);
    expect(r.distanceMeters, 15.67);
    expect(r.insideGeofence, isTrue);
    expect(r.attendance.id, 228);
  });

  test('correction + overtime objects', () {
    final c = CorrectionRequest.fromJson(JsonReader(correctionJson()));
    expect(c.status, RequestStatus.pending);
    expect(c.requestedCheckOutAt!.hhmm, '17:05');
    expect(c.requestedCheckInAt, isNull);
    expect(RequestStatus.parse('CANCELLED'), RequestStatus.cancelled);

    final o = OvertimeRequest.fromJson(
      JsonReader(overtimeJson(status: 'APPROVED')),
    );
    expect(o.status, RequestStatus.approved);
    expect(o.requestedMinutes, 120);
    expect(o.startAt!.hhmm, '17:00');
  });

  test('ZonedTime keeps the company offset for wall-clock rendering', () {
    final t = ZonedTime.tryParse('2026-09-30T23:30:00+07:00')!;
    expect(t.utc, DateTime.utc(2026, 9, 30, 16, 30));
    expect(t.hhmm, '23:30');
    expect(t.dateKey, '2026-09-30');
    expect(t.toIso(), '2026-09-30T23:30:00+07:00');

    final neg = ZonedTime.tryParse('2026-01-01T01:00:00-05:00')!;
    expect(neg.hhmm, '01:00');
    expect(neg.utc, DateTime.utc(2026, 1, 1, 6));

    final built = ZonedTime.fromWall(
      DateTime(2026, 9, 25),
      17,
      5,
      const Duration(hours: 7),
    );
    expect(built.toIso(), '2026-09-25T17:05:00+07:00');
    expect(ZonedTime.tryParse('nonsense'), isNull);
  });

  group('ApiAttendanceRepository against wire JSON', () {
    late _RecordingAdapter adapter;
    late ApiAttendanceRepository repo;

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      adapter = _RecordingAdapter();
      final dio = Dio(BaseOptions(validateStatus: (s) => s != null && s < 300))
        ..httpClientAdapter = adapter;
      repo = ApiAttendanceRepository(
        client: ApiClient(
          dio: dio,
          readToken: () async => 't',
          baseUrl: () => 'http://api.test',
          onUnauthorized: () {},
          retryDelays: const [],
        ),
        cache: JsonCache(await SharedPreferences.getInstance()),
        clock: () => DateTime.utc(2026, 9, 30, 4),
      );
    });

    OutboxItem item({bool offline = false}) => OutboxItem(
      idempotencyKey: 'abc-12345',
      employeeId: 6,
      type: CheckType.checkIn,
      latitude: -6.9218,
      longitude: 107.6071,
      accuracyMeters: 12,
      locationCapturedAt: DateTime.utc(2026, 9, 30, 1),
      deviceCapturedAt: DateTime.utc(2026, 9, 30, 1, 0, 30),
      createdAt: DateTime.utc(2026, 9, 30, 1),
      isOfflineSync: offline,
      isMockLocation: true,
    );

    test('check-in request body matches the documented fields', () async {
      adapter.respond = (o) => okJson(checkResultJson());
      final res = await repo.submit(item(offline: true));

      final req = adapter.requests.single;
      expect(req.method, 'POST');
      expect(req.path, contains('/api/v1/attendance/check-in'));
      final body = req.data as Map<String, dynamic>;
      expect(body['idempotency_key'], 'abc-12345');
      expect(body['latitude'], -6.9218);
      expect(body['accuracy_meters'], 12);
      expect(body['is_offline_sync'], true);
      expect(body['is_mock_location'], true);
      expect(body['device_captured_at'], '2026-09-30T01:00:30.000Z');
      expect(body['location_captured_at'], '2026-09-30T01:00:00.000Z');
      expect(
        body.containsKey('inside_geofence'),
        isFalse,
        reason: 'the client opinion is never sent',
      );
      expect(res.attendance.status, 'CHECKED_IN');
    });

    test(
      'a selfie switches the request to multipart with 1/0 booleans',
      () async {
        adapter.respond = (o) => okJson(checkResultJson());
        await repo.submit(
          item(offline: true),
          selfie: Uint8List.fromList([1, 2, 3]),
        );

        final form = adapter.requests.single.data as FormData;
        final fields = {for (final f in form.fields) f.key: f.value};
        expect(fields['idempotency_key'], 'abc-12345');
        expect(fields['is_offline_sync'], '1');
        expect(fields['is_mock_location'], '1');
        expect(form.files.single.key, 'selfie');
      },
    );

    test(
      'today() is cached and served (stale) when the network is down',
      () async {
        adapter.respond = (o) => okJson(todayJson(state: 'CHECKED_IN'));
        final live = await repo.today();
        expect(live.isStale, isFalse);

        adapter.fail = true;
        final cached = await repo.today();
        expect(cached.isStale, isTrue);
        expect(cached.state, TodayState.checkedIn);
      },
    );

    test('history() merges pages and sorts newest first', () async {
      adapter.respond = (o) {
        final page = int.parse(o.queryParameters['page'].toString());
        final d = page == 1 ? '2026-09-01' : '2026-09-02';
        return okJson(
          [
            {...attendanceJson(), 'id': page, 'work_date': d},
          ],
          meta: {'page': page, 'last_page': 2},
        );
      };
      final list = await repo.history(
        from: DateTime(2026, 9),
        to: DateTime(2026, 9, 30),
      );
      expect(list.map((e) => e.id), [2, 1]);
      expect(adapter.requests.first.queryParameters['from'], '2026-09-01');
    });
  });
}

class _RecordingAdapter implements HttpClientAdapter {
  final List<RequestOptions> requests = [];
  Object Function(RequestOptions options) respond = (_) => okJson(null);
  bool fail = false;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    if (fail) {
      throw DioException(
        requestOptions: options,
        type: DioExceptionType.connectionError,
      );
    }
    return ResponseBody.fromString(
      jsonEncode(respond(options)),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
