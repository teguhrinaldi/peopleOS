@Timeout(Duration(minutes: 2))
library;

import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/json_cache.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/storage/token_store.dart';
import 'package:peopleos/features/attendance/application/attendance_sync_service.dart';
import 'package:peopleos/features/attendance/data/outbox_store.dart';
import 'package:peopleos/features/attendance/data/selfie_store.dart';
import 'package:peopleos/features/attendance/data/api_attendance_repository.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/auth/data/api_auth_repository.dart';
import 'package:peopleos/features/leave/data/api_leave_repository.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/notifications/data/api_notifications_repository.dart';
import 'package:peopleos/features/requests/data/api_requests_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

import '../helpers/fakes.dart';

/// End-to-end check of the API repositories against a running Laravel server.
///
///   PEOPLEOS_API_TESTS=1 flutter test test/api/live_api_test.dart
///   (optional) PEOPLEOS_API_URL=http://127.0.0.1:8000
///   (optional) PEOPLEOS_API_USER=jessica@peopleos.test
///
/// Skipped unless PEOPLEOS_API_TESTS=1. It records real attendance for the
/// chosen demo user in the connected database.
void main() {
  offlineReplaySuite();
  leaveSuite();
  provenanceSuite();
  final enabled = Platform.environment['PEOPLEOS_API_TESTS'] == '1';
  final baseUrl =
      Platform.environment['PEOPLEOS_API_URL'] ?? 'http://127.0.0.1:8000';
  final email =
      Platform.environment['PEOPLEOS_API_USER'] ?? 'jessica@peopleos.test';

  test(
    'login -> today -> check-in (GPS) -> replay same key -> check-out',
    () async {
      // flutter_test blocks real sockets by default.
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      var unauthorized = 0;
      final client = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => baseUrl,
        onUnauthorized: () => unauthorized++,
      );
      final cache = JsonCache(prefs);
      final auth = ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      );
      final attendance = ApiAttendanceRepository(client: client, cache: cache);

      // ---- auth ---------------------------------------------------------
      await expectLater(
        auth.login(email: email, password: 'definitely-wrong'),
        throwsA(
          isA<UnauthorizedException>().having(
            (e) => e.isBadCredentials,
            'bad credentials',
            true,
          ),
        ),
      );
      final session = await auth.login(email: email, password: 'password');
      // ignore: avoid_print
      print(
        'LOGIN ok: ${session.employee.fullName} ${session.employee.employeeNumber} '
        'tz=${session.company.timezone}',
      );
      expect(await tokens.read(), isNotNull);
      final me = await auth.refresh();
      expect(me.employee.id, session.employee.id);

      // ---- today --------------------------------------------------------
      var today = await attendance.today();
      // ignore: avoid_print
      print(
        'TODAY state=${today.state} canIn=${today.canCheckIn} '
        'canOut=${today.canCheckOut} '
        'site=${today.schedule?.location?.name} '
        'selfie=${today.schedule?.policy.requireSelfie}',
      );
      final site = today.schedule?.location;
      expect(site, isNotNull, reason: 'demo user must have a schedule');

      if (!today.canCheckIn) {
        // ignore: avoid_print
        print('SKIP write steps: today is already ${today.state}.');
        return;
      }

      // ---- outside geofence (server is the authority) ---------------------
      final far = OutboxItem(
        idempotencyKey: const Uuid().v4(),
        employeeId: session.employee.id,
        type: CheckType.checkIn,
        latitude: site!.latitude + 0.05,
        longitude: site.longitude,
        accuracyMeters: 10,
        locationCapturedAt: DateTime.now().toUtc(),
        deviceCapturedAt: DateTime.now().toUtc(),
        createdAt: DateTime.now().toUtc(),
      );
      await expectLater(
        attendance.submit(far, selfie: tinyPng),
        throwsA(
          isA<ValidationException>()
              .having((e) => e.code, 'code', 'OUTSIDE_GEOFENCE')
              .having(
                (e) => e.details['distance_meters'],
                'distance',
                isA<num>(),
              ),
        ),
      );
      // ignore: avoid_print
      print('OUTSIDE_GEOFENCE surfaced as expected');

      // ---- check-in inside the geofence -----------------------------------
      OutboxItem inside(CheckType type, String key, {bool offline = false}) =>
          OutboxItem(
            idempotencyKey: key,
            employeeId: session.employee.id,
            type: type,
            latitude: site.latitude + 0.0002,
            longitude: site.longitude,
            accuracyMeters: 8,
            locationCapturedAt: DateTime.now().toUtc(),
            deviceCapturedAt: DateTime.now().toUtc(),
            createdAt: DateTime.now().toUtc(),
            isOfflineSync: offline,
          );
      final needsSelfie = today.schedule!.policy.requireSelfie;
      final key = const Uuid().v4();

      final first = await attendance.submit(
        inside(CheckType.checkIn, key),
        selfie: needsSelfie ? tinyPng : null,
      );
      // ignore: avoid_print
      print(
        'CHECK-IN status=${first.attendance.status} '
        'in=${first.attendance.checkInTime} '
        'dist=${first.distanceMeters} replayed=${first.replayed}',
      );
      expect(first.replayed, isFalse);
      expect(first.attendance.status, 'CHECKED_IN');
      expect(first.insideGeofence, isTrue);

      // Replaying the same idempotency key (as the outbox does after a
      // timeout) must be accepted and must not create a second record.
      final replay = await attendance.submit(
        inside(CheckType.checkIn, key, offline: true),
        selfie: needsSelfie ? tinyPng : null,
      );
      // ignore: avoid_print
      print('REPLAY replayed=${replay.replayed} id=${replay.attendance.id}');
      expect(replay.replayed, isTrue);
      expect(replay.attendance.id, first.attendance.id);

      today = await attendance.today();
      expect(today.state, TodayState.checkedIn);
      expect(today.canCheckOut, isTrue);

      // A second check-in with a NEW key is a business conflict.
      await expectLater(
        attendance.submit(
          inside(CheckType.checkIn, const Uuid().v4()),
          selfie: needsSelfie ? tinyPng : null,
        ),
        throwsA(
          isA<ConflictException>().having(
            (e) => e.code,
            'code',
            'ALREADY_CHECKED_IN',
          ),
        ),
      );

      // ---- check-out ------------------------------------------------------
      final out = await attendance.submit(
        inside(CheckType.checkOut, const Uuid().v4()),
        selfie: needsSelfie ? tinyPng : null,
      );
      // ignore: avoid_print
      print(
        'CHECK-OUT status=${out.attendance.status} '
        'out=${out.attendance.checkOutTime} '
        'worked=${out.attendance.workedMinutes}',
      );
      expect(out.attendance.status, 'CHECKED_OUT');

      // ---- history + detail -----------------------------------------------
      final now = DateTime.now();
      final history = await attendance.history(
        from: DateTime(now.year, now.month),
        to: DateTime(now.year, now.month + 1, 0),
      );
      expect(history.any((r) => r.id == first.attendance.id), isTrue);
      final detail = await attendance.detail(first.attendance.id);
      expect(detail.checkIn, isNotNull);
      expect(unauthorized, 0);

      // ---- requests -------------------------------------------------------
      final requests = ApiRequestsRepository(client: client, cache: cache);
      // ignore: avoid_print
      print(
        'CORRECTIONS=${(await requests.corrections()).length} '
        'OVERTIME=${(await requests.overtime()).length}',
      );

      // ---- logout invalidates the token -----------------------------------
      await auth.logout();
      expect(await tokens.read(), isNull);
    },
    skip: enabled
        ? false
        : 'Set PEOPLEOS_API_TESTS=1 to run against a live server',
  );
}

/// Full offline path against the real server: the action is captured while
/// "offline", stored in the outbox, then delivered with is_offline_sync=true
/// and the original device time.
void offlineReplaySuite() {
  final enabled = Platform.environment['PEOPLEOS_API_TESTS'] == '1';
  final baseUrl =
      Platform.environment['PEOPLEOS_API_URL'] ?? 'http://127.0.0.1:8000';
  final email =
      Platform.environment['PEOPLEOS_API_USER2'] ?? 'olivia@peopleos.test';

  test(
    'offline check-in is queued, replayed with device time and marked offline',
    () async {
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      final client = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => baseUrl,
        onUnauthorized: () {},
      );
      final cache = JsonCache(prefs);
      final session = await ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      ).login(email: email, password: 'password');
      final repo = ApiAttendanceRepository(client: client, cache: cache);
      final today = await repo.today();
      // ignore: avoid_print
      print('OFFLINE-SUITE ${session.employee.fullName} state=${today.state}');
      if (!today.canCheckIn || today.schedule?.location == null) {
        // ignore: avoid_print
        print('SKIP: cannot check in today (${today.state})');
        return;
      }
      final site = today.schedule!.location!;
      final net = FakeConnectivity(online: false);
      final store = MemoryOutboxStore();
      // The user taps 20 minutes ago (device clock); the server sees it later.
      final tapped = DateTime.now().toUtc().subtract(
        const Duration(minutes: 20),
      );
      var clockNow = tapped;
      final service = AttendanceSyncService(
        repository: repo,
        store: store,
        selfies: MemorySelfieStore(),
        connectivity: net,
        clock: () => clockNow,
      );

      final queued = await service.perform(
        CheckCapture(
          employeeId: session.employee.id,
          type: CheckType.checkIn,
          fix: LocationFix(
            latitude: site.latitude + 0.0002,
            longitude: site.longitude,
            accuracyMeters: 9,
            capturedAt: tapped,
          ),
          selfie: today.schedule!.policy.requireSelfie ? tinyPng : null,
          utcOffsetMinutes: today.serverTime.offset.inMinutes,
        ),
      );
      expect(queued.kind, SubmitKind.savedOffline);
      expect(queued.item.syncState, SyncState.pendingSync);

      net.set(true);
      clockNow = DateTime.now().toUtc();
      final report = await service.sync(session.employee.id, force: true);
      expect(report.synced, 1, reason: 'queued action must be accepted');

      final after = await repo.today();
      final event = after.attendance!.checkIn!;
      // ignore: avoid_print
      print(
        'REPLAYED offline=${event.isOfflineSync} '
        'in=${after.attendance!.checkInTime} tapped(utc)=$tapped',
      );
      expect(event.isOfflineSync, isTrue);
      expect(
        event.occurredAt!.utc.difference(queued.item.deviceCapturedAt).abs(),
        lessThan(const Duration(minutes: 2)),
        reason: 'server honours device_captured_at for offline replays',
      );
      await ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      ).logout();
    },
    skip: enabled
        ? false
        : 'Set PEOPLEOS_API_TESTS=1 to run against a live server',
  );
}

/// Leave + notifications against the live server (Michael by default).
/// Leaves no trace except one cancelled request.
void leaveSuite() {
  final enabled = Platform.environment['PEOPLEOS_API_TESTS'] == '1';
  final baseUrl =
      Platform.environment['PEOPLEOS_API_URL'] ?? 'http://127.0.0.1:8000';
  final email =
      Platform.environment['PEOPLEOS_API_LEAVE_USER'] ??
      'employee@peopleos.test';

  test(
    'leave: types, balances, preview, draft -> submit -> cancel, errors; notifications',
    () async {
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      final client = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => baseUrl,
        onUnauthorized: () {},
      );
      final cache = JsonCache(prefs);
      final auth = ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      );
      await auth.login(email: email, password: 'password');
      final leave = ApiLeaveRepository(client: client, cache: cache);

      final types = await leave.types();
      final annual = types.firstWhere((t) => t.ref.code == 'ANNUAL');
      final sick = types.firstWhere((t) => t.ref.code == 'SICK');
      // ignore: avoid_print
      print(
        'LEAVE types=${types.map((t) => t.ref.code).join(',')} '
        'annualNotice=${annual.policy?.minNoticeDays}',
      );
      expect(sick.policy!.unlimited, isTrue);

      final before = (await leave.balances()).firstWhere(
        (b) => b.type.id == annual.id,
      );
      // ignore: avoid_print
      print(
        'BALANCE annual entitlement=${before.entitlement} used=${before.used} '
        'reserved=${before.reserved} available=${before.available}',
      );

      // Far enough ahead for the notice period, Mon-Tue.
      var start = DateTime.now().add(const Duration(days: 40));
      while (start.weekday != DateTime.monday) {
        start = start.add(const Duration(days: 1));
      }
      final draft = LeaveDraft(
        typeId: annual.id,
        start: start,
        end: start.add(const Duration(days: 1)),
        reason: 'Live API test',
      );
      final preview = await leave.preview(draft);
      // ignore: avoid_print
      print('PREVIEW days=${preview.days} after=${preview.availableAfter}');
      expect(preview.days, 2);

      final created = await leave.create(draft, submit: false);
      expect(created.status, LeaveStatus.draft);
      final edited = await leave.update(
        created.id,
        LeaveDraft(
          typeId: annual.id,
          start: start,
          end: start,
          halfDay: HalfDay.am,
          reason: 'Live API test (half day)',
        ),
      );
      expect(edited.halfDay, HalfDay.am);
      // ignore: avoid_print
      print('DRAFT days reported by the API: ${edited.days}');
      final submitted = await leave.submit(created.id);
      expect(submitted.status, LeaveStatus.pending);
      expect(submitted.days, 0.5);
      final reserved = (await leave.balances()).firstWhere(
        (b) => b.type.id == annual.id,
      );
      expect(reserved.reserved, before.reserved + 0.5);

      // Same half-day again: overlap.
      await expectLater(
        leave.create(
          LeaveDraft(
            typeId: annual.id,
            start: start,
            end: start,
            halfDay: HalfDay.am,
          ),
          submit: true,
        ),
        throwsA(
          isA<AppException>().having((e) => e.code, 'code', 'LEAVE_OVERLAP'),
        ),
      );
      // Far too long: not enough balance, with numbers.
      await expectLater(
        leave.create(
          LeaveDraft(
            typeId: annual.id,
            start: start.add(const Duration(days: 7)),
            end: start.add(const Duration(days: 32)),
          ),
          submit: true,
        ),
        throwsA(
          isA<ValidationException>()
              .having((e) => e.code, 'code', 'LEAVE_INSUFFICIENT_BALANCE')
              .having((e) => e.details['available'], 'available', isNotNull),
        ),
      );
      // Tomorrow violates the notice period.
      final tomorrow = DateTime.now().add(const Duration(days: 1));
      await expectLater(
        leave.preview(
          LeaveDraft(typeId: annual.id, start: tomorrow, end: tomorrow),
        ),
        throwsA(
          isA<AppException>().having(
            (e) => e.code,
            'code',
            anyOf('LEAVE_NOTICE_TOO_SHORT', 'LEAVE_NO_WORKING_DAYS'),
          ),
        ),
      );

      final cancelled = await leave.cancel(
        submitted.id,
        reason: 'Live test cleanup',
      );
      expect(cancelled.status, LeaveStatus.cancelled);
      await expectLater(
        leave.cancel(submitted.id),
        throwsA(
          isA<ConflictException>().having(
            (e) => e.code,
            'code',
            'REQUEST_NOT_PENDING',
          ),
        ),
      );
      final after = (await leave.balances()).firstWhere(
        (b) => b.type.id == annual.id,
      );
      expect(
        after.reserved,
        before.reserved,
        reason: 'cancel released the days',
      );

      final mine = await leave.requests();
      final detail = await leave.detail(mine.first.id);
      // ignore: avoid_print
      print(
        'REQUESTS=${mine.length} first=${detail.type.name} ${detail.status.wire} '
        'workingDates=${detail.workingDates.length}',
      );

      final inbox = ApiNotificationsRepository(client);
      final page = await inbox.list();
      final unread = await inbox.unreadCount();
      // ignore: avoid_print
      print(
        'NOTIFICATIONS items=${page.items.length} unread=$unread '
        'first=${page.items.firstOrNull?.type}',
      );
      expect(page.unreadCount, unread);
      if (page.items.isNotEmpty) {
        await inbox.markRead(page.items.first.id);
        await inbox.markRead(page.items.first.id); // idempotent
      }
      await auth.logout();
    },
    skip: enabled
        ? false
        : 'Set PEOPLEOS_API_TESTS=1 to run against a live server',
  );
}

/// Data provenance: login -> today -> history -> leave / overtime / correction
/// lists straight from the server, then the same repositories against a dead
/// server (wrong port) must fail instead of inventing data.
void provenanceSuite() {
  final enabled = Platform.environment['PEOPLEOS_API_TESTS'] == '1';
  final baseUrl =
      Platform.environment['PEOPLEOS_API_URL'] ?? 'http://127.0.0.1:8000';
  final email =
      Platform.environment['PEOPLEOS_API_LEAVE_USER'] ??
      'employee@peopleos.test';

  test(
    'provenance: live reads match the server; a wrong port fails with an error',
    () async {
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final tokens = MemoryTokenStore();
      final cache = JsonCache(prefs);
      final client = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => baseUrl,
        onUnauthorized: () {},
        localeTag: () => 'id',
      );
      final auth = ApiAuthRepository(
        client: client,
        tokens: tokens,
        cache: cache,
      );
      final session = await auth.login(email: email, password: 'password');
      // The session exposes the server-side language preference (or null).
      // ignore: avoid_print
      print(
        'LOGIN ${session.employee.fullName} serverLocale=${session.serverLocale}',
      );

      final attendance = ApiAttendanceRepository(client: client, cache: cache);
      final today = await attendance.today();
      // ignore: avoid_print
      print('TODAY ${today.state} ${today.today} stale=${today.isStale}');
      expect(today.isStale, isFalse);
      expect(cache.staleSince.value, isNull, reason: 'live read is not stale');

      final now = DateTime.now();
      final history = await attendance.history(
        from: DateTime(now.year, now.month - 1),
        to: DateTime(now.year, now.month + 1, 0),
      );
      // ignore: avoid_print
      print('HISTORY records=${history.length}');
      expect(history.every((r) => r.id > 0), isTrue);

      final leave = await ApiLeaveRepository(
        client: client,
        cache: cache,
      ).requests();
      // PEOPLEOS_API_SKIP_OVERTIME=1 skips only this read, for a backend
      // whose /overtime-requests is temporarily broken. The skip is printed.
      final skipOvertime =
          Platform.environment['PEOPLEOS_API_SKIP_OVERTIME'] == '1';
      if (skipOvertime) {
        // ignore: avoid_print
        print('SKIPPED overtime list (PEOPLEOS_API_SKIP_OVERTIME=1)');
      }
      final overtime = skipOvertime
          ? const <Object>[]
          : await ApiRequestsRepository(
              client: client,
              cache: cache,
            ).overtime();
      final corrections = await ApiRequestsRepository(
        client: client,
        cache: cache,
      ).corrections();
      // ignore: avoid_print
      print(
        'LISTS leave=${leave.length} overtime=${overtime.length} corrections=${corrections.length}',
      );
      expect(cache.staleSince.value, isNull);

      // Preference endpoint of the locale contract (handled gracefully when absent).
      final saved = await auth.savePreferredLocale('id');
      // ignore: avoid_print
      print('PREFERENCE saved=$saved');
      if (saved) {
        final me = await auth.refresh();
        expect(me.serverLocale, 'id');
        await auth.savePreferredLocale(null);
      }

      // ---- the same reads against a wrong port --------------------------
      final dead = ApiClient.create(
        readToken: tokens.read,
        baseUrl: () => 'http://127.0.0.1:1',
        onUnauthorized: () {},
      );
      final emptyCache = JsonCache(prefs);
      await emptyCache.clear();
      await expectLater(
        ApiAttendanceRepository(client: dead, cache: emptyCache).today(),
        throwsA(
          isA<AppException>().having((e) => e.isTransient, 'transient', true),
        ),
      );
      await expectLater(
        ApiLeaveRepository(client: dead, cache: emptyCache).requests(),
        throwsA(isA<AppException>()),
      );
      expect(
        emptyCache.staleSince.value,
        isNull,
        reason: 'nothing cached, nothing shown',
      );

      // With a cache from the live run the data is served but marked stale.
      final warm = ApiLeaveRepository(client: client, cache: cache);
      await warm.requests(); // fills the cache
      final cold = ApiLeaveRepository(client: dead, cache: cache);
      final fromCache = await cold.requests();
      expect(fromCache.length, leave.length);
      expect(
        cache.staleSince.value,
        isNotNull,
        reason: 'cached data is labelled stale',
      );
      // ignore: avoid_print
      print(
        'DEAD SERVER: no cache -> error; with cache -> ${fromCache.length} items flagged stale since ${cache.staleSince.value}',
      );
      await auth.logout();
    },
    skip: enabled
        ? false
        : 'Set PEOPLEOS_API_TESTS=1 to run against a live server',
  );
}
