import 'dart:convert';
import 'dart:typed_data';

import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/utils/geo.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/demo/demo_data.dart';
import 'package:peopleos/features/demo/demo_leave.dart';
import 'package:peopleos/features/demo/demo_payroll.dart';
import 'package:peopleos/features/demo/demo_pdf.dart';
import 'package:peopleos/features/demo/demo_seed.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// In-app stand-in for the Laravel server used by Demo mode.
///
/// It enforces the same rules as the real API (geofence, selfie policy,
/// idempotency, state conflicts, correction window ...) and returns the same
/// JSON shapes, persisted in SharedPreferences so the demo survives restarts.
class DemoBackend {
  DemoBackend({
    required this.prefs,
    required this.settings,
    DateTime Function()? clock,
    this.latency = const Duration(milliseconds: 350),
  }) : _clock = clock ?? DateTime.now;

  final SharedPreferences prefs;
  final AppSettings settings;
  final Duration latency;
  final DateTime Function() _clock;

  static const _stateKey = 'demo.state.v1';
  static const _sessionKey = 'demo.signed_in';

  Duration get offset => _clock().timeZoneOffset;

  DateTime get _nowUtc => _clock().toUtc();

  ZonedTime get _now => ZonedTime(_nowUtc, offset);

  DateTime get _todayDate {
    final w = _now.wall;
    return DateTime(w.year, w.month, w.day);
  }

  Map<String, dynamic>? _state;

  Map<String, dynamic> get _s {
    if (_state != null) return _state!;
    final raw = prefs.getString(_stateKey);
    if (raw != null) {
      _state = jsonDecode(raw) as Map<String, dynamic>;
    } else {
      _state = _seed();
      _persist();
    }
    return _state!;
  }

  Future<void> _persist() => prefs.setString(_stateKey, jsonEncode(_state));

  Map<String, dynamic> _seed() {
    final today = _todayDate;
    String d(int back) =>
        formatDateOnly(DateTime(today.year, today.month, today.day - back));
    ZonedTime? at(int back, int minutes) => ZonedTime(
      DateTime.utc(
        today.year,
        today.month,
        today.day - back,
      ).add(Duration(minutes: minutes) - offset),
      offset,
    );
    return {
      'next_id': 100,
      'records': <String, dynamic>{},
      'keys': <String, dynamic>{},
      'overrides': <String, dynamic>{},
      'corrections': <Map<String, dynamic>>[
        {
          'id': 91,
          'attendance_id': 5006,
          'work_date': d(6),
          'requested_check_in_at': null,
          'requested_check_out_at': at(6, 17 * 60 + 5)!.toIso(),
          'requested_check_in_time': null,
          'requested_check_out_time': '17:05',
          'reason': 'Forgot to check out after the client meeting.',
          'status': 'APPROVED',
          'reviewed_at': at(5, 9 * 60)!.toIso(),
          'review_note': 'Confirmed with your manager.',
          'created_at': at(6, 18 * 60)!.toIso(),
        },
        {
          'id': 90,
          'attendance_id': 5015,
          'work_date': d(15),
          'requested_check_in_at': at(15, 8 * 60)!.toIso(),
          'requested_check_out_at': null,
          'requested_check_in_time': '08:00',
          'requested_check_out_time': null,
          'reason': 'App crashed when I arrived.',
          'status': 'REJECTED',
          'reviewed_at': at(14, 10 * 60)!.toIso(),
          'review_note': 'No supporting evidence for the requested time.',
          'created_at': at(15, 12 * 60)!.toIso(),
        },
      ],
      'overtime': <Map<String, dynamic>>[
        {
          'id': 81,
          'work_date': d(8),
          'start_at': at(8, 17 * 60)!.toIso(),
          'end_at': at(8, 19 * 60 + 30)!.toIso(),
          'start_time': '17:00',
          'end_time': '19:30',
          'requested_minutes': 150,
          'approved_minutes': 120,
          'reason': 'Campaign launch preparation.',
          'status': 'APPROVED',
          'reviewed_at': at(7, 9 * 60)!.toIso(),
          'review_note': 'Approved for 2 hours.',
          'created_at': at(8, 17 * 60 + 10)!.toIso(),
        },
      ],
    };
  }

  /// Simulated offline mode: writes fail like a dead network, reads keep
  /// working from local data (the real app serves cached reads the same way).
  Future<T> _call<T>(T Function() body, {bool write = false}) async {
    if (latency > Duration.zero) await Future<void>.delayed(latency);
    if (write && settings.demoOffline) throw const NoConnectionException();
    return body();
  }

  /// Wipes demo data (attendance records, requests, idempotency keys).
  Future<void> reset() async {
    _state = null;
    await prefs.remove(_stateKey);
  }

  // ---- auth ---------------------------------------------------------------

  bool get signedIn => prefs.getBool(_sessionKey) ?? false;

  Future<Map<String, dynamic>> login(String email, String password) =>
      _call(write: true, () {
        if (email.trim().toLowerCase() != DemoData.email ||
            password != DemoData.password) {
          throw const UnauthorizedException(
            'The email or password is incorrect.',
            code: 'INVALID_CREDENTIALS',
          );
        }
        prefs.setBool(_sessionKey, true);
        return _session();
      });

  Map<String, dynamic> _session() {
    final tz = _clock().timeZoneName;
    final json = DemoData.session(tz.isEmpty ? 'Device time' : tz);
    if (settings.demoScenario == DemoScenario.terminated) {
      (json['employee'] as Map<String, dynamic>)
        ..['status'] = 'TERMINATED'
        ..['terminated_at'] = formatDateOnly(_todayDate);
    }
    return json;
  }

  Map<String, dynamic> session() => _session();

  Future<void> logout() async => prefs.setBool(_sessionKey, false);

  // ---- attendance ---------------------------------------------------------

  Map<String, dynamic>? _todayRecord() =>
      (_s['records'] as Map<String, dynamic>)[formatDateOnly(_todayDate)]
          as Map<String, dynamic>?;

  Future<Map<String, dynamic>> today() => _call(() {
    final scenario = settings.demoScenario;
    final working = scenario != DemoScenario.restDay;
    final schedule = DemoData.schedule(
      workDate: _todayDate,
      offset: offset,
      workingDay: working,
      requireSelfie: scenario == DemoScenario.selfieRequired,
    );
    final record = _todayRecord();

    String state;
    String? blocked;
    switch (scenario) {
      case DemoScenario.terminated:
        state = 'BLOCKED';
        blocked = 'EMPLOYEE_TERMINATED';
      case DemoScenario.noSchedule:
        state = 'NO_SCHEDULE';
        blocked = 'NO_SCHEDULE';
      case DemoScenario.restDay:
        state = 'NOT_WORKING_DAY';
        blocked = 'NOT_A_WORKING_DAY';
      default:
        if (record == null && _onLeaveToday) {
          state = 'ON_LEAVE';
          blocked = 'ON_LEAVE';
        } else if (record == null) {
          state = 'NOT_CHECKED_IN';
        } else if (record['check_out_at'] == null) {
          state = 'CHECKED_IN';
        } else {
          state = 'CHECKED_OUT';
        }
    }
    final blockedState = blocked != null;
    int? elapsed;
    if (state == 'CHECKED_IN') {
      final inAt = ZonedTime.tryParse(record!['check_in_at'])!;
      elapsed = _nowUtc.difference(inAt.utc).inMinutes;
    }
    return {
      'server_time': _now.toIso(),
      'timezone': _session()['company']['timezone'],
      'today': formatDateOnly(_todayDate),
      'work_date': formatDateOnly(_todayDate),
      'state': state,
      'blocked_reason': blocked,
      'can_check_in': state == 'NOT_CHECKED_IN',
      'can_check_out': state == 'CHECKED_IN',
      'elapsed_minutes': elapsed,
      'schedule': scenario == DemoScenario.noSchedule ? null : schedule,
      'attendance': blockedState ? null : record,
      'holiday': null,
      'pending_correction': null,
    };
  });

  Future<List<Map<String, dynamic>>> history(DateTime from, DateTime to) =>
      _call(() {
        final overrides = _s['overrides'] as Map<String, dynamic>;
        final all = <Map<String, dynamic>>[
          ...(_s['records'] as Map<String, dynamic>).values
              .cast<Map<String, dynamic>>(),
          ...generateDemoHistory(today: _todayDate, offset: offset).map((r) {
            final o = overrides[r['work_date']];
            return o == null ? r : {...r, 'status': o};
          }),
        ];
        final f = formatDateOnly(from);
        final t = formatDateOnly(to);
        return all
            .where(
              (r) =>
                  (r['work_date'] as String).compareTo(f) >= 0 &&
                  (r['work_date'] as String).compareTo(t) <= 0,
            )
            .toList()
          ..sort(
            (a, b) =>
                (b['work_date'] as String).compareTo(a['work_date'] as String),
          );
      });

  Future<Map<String, dynamic>> detail(int id) async {
    final all = await history(DateTime(2000), DateTime(2100));
    return all.firstWhere(
      (r) => r['id'] == id,
      orElse: () => throw const NotFoundException('Attendance not found.'),
    );
  }

  Future<Map<String, dynamic>> submit(OutboxItem item, Uint8List? selfie) =>
      _call(write: true, () {
        final keys = _s['keys'] as Map<String, dynamic>;
        final previous = keys[item.idempotencyKey];
        if (previous != null) {
          return {...(previous as Map<String, dynamic>), 'replayed': true};
        }

        final scenario = settings.demoScenario;
        if (item.type == CheckType.checkIn && _onLeaveToday) {
          throw const ConflictException(
            'You are on approved leave today.',
            code: 'ON_LEAVE',
          );
        }
        if (scenario == DemoScenario.terminated) {
          throw const ForbiddenException(
            'Employment has ended.',
            code: 'EMPLOYEE_TERMINATED',
          );
        }
        if (scenario == DemoScenario.noSchedule) {
          throw const ValidationException('No schedule.', code: 'NO_SCHEDULE');
        }
        if (scenario == DemoScenario.restDay) {
          throw const ValidationException(
            'Not a working day.',
            code: 'NOT_A_WORKING_DAY',
          );
        }

        _validateLocation(item);
        if (scenario == DemoScenario.selfieRequired && selfie == null) {
          throw const ValidationException(
            'Selfie required.',
            code: 'SELFIE_REQUIRED',
          );
        }

        var occurred = _now;
        if (item.isOfflineSync) {
          final age = _nowUtc.difference(item.deviceCapturedAt.toUtc());
          if (age.inMinutes > 720) {
            throw const ValidationException(
              'Offline event too old.',
              code: 'OFFLINE_EVENT_TOO_OLD',
            );
          }
          occurred = ZonedTime(item.deviceCapturedAt.toUtc(), offset);
        }

        final dateKey = occurred.dateKey;
        final records = _s['records'] as Map<String, dynamic>;
        final existing = records[dateKey] as Map<String, dynamic>?;
        final distance = haversineMeters(
          item.latitude ?? DemoData.siteLat,
          item.longitude ?? DemoData.siteLng,
          DemoData.siteLat,
          DemoData.siteLng,
        );
        final event = _event(item, occurred, distance, selfie != null);

        Map<String, dynamic> record;
        if (item.type == CheckType.checkIn) {
          if (existing != null) {
            throw ConflictException(
              existing['check_out_at'] == null
                  ? 'Already checked in.'
                  : 'Already checked out today.',
              code: existing['check_out_at'] == null
                  ? 'ALREADY_CHECKED_IN'
                  : 'ALREADY_CHECKED_OUT',
            );
          }
          final inMin = occurred.wall.hour * 60 + occurred.wall.minute;
          // Same rule as the server: once grace is exceeded, the whole delay counts as late.
          final late = inMin > DemoData.startMinutes + DemoData.graceMinutes;
          record = {
            'id': _nextId(),
            'employee_id': 12,
            'work_date': dateKey,
            'status': 'CHECKED_IN',
            'needs_review': false,
            'check_in_at': occurred.toIso(),
            'check_out_at': null,
            'check_in_time': occurred.hhmm,
            'check_out_time': null,
            'worked_minutes': 0,
            'late_minutes': late ? inMin - DemoData.startMinutes : 0,
            'early_leave_minutes': 0,
            'is_late': late,
            'is_early_leave': false,
            'review_note': null,
            'shift_name': 'Regular Shift',
            'location_name': DemoData.siteName,
            'day_type': 'workday',
            'expected_start_time': '08:00',
            'expected_end_time': '17:00',
            'check_in': event,
            'check_out': null,
          };
        } else {
          if (existing == null || existing['check_in_at'] == null) {
            throw const ConflictException(
              'Not checked in.',
              code: 'NOT_CHECKED_IN',
            );
          }
          if (existing['check_out_at'] != null) {
            throw const ConflictException(
              'Already checked out.',
              code: 'ALREADY_CHECKED_OUT',
            );
          }
          final inAt = ZonedTime.tryParse(existing['check_in_at'])!;
          final gross = occurred.utc.difference(inAt.utc).inMinutes;
          final outMin = occurred.wall.hour * 60 + occurred.wall.minute;
          final early = outMin < DemoData.endMinutes;
          record = {
            ...existing,
            'status': 'CHECKED_OUT',
            'check_out_at': occurred.toIso(),
            'check_out_time': occurred.hhmm,
            'worked_minutes': gross > 300
                ? gross - DemoData.breakMinutes
                : gross,
            'is_early_leave': early,
            'early_leave_minutes': early ? DemoData.endMinutes - outMin : 0,
            'check_out': event,
          };
        }
        records[dateKey] = record;
        final response = {
          'attendance': record,
          'event': {
            'id': _nextId(),
            'type': item.type.wire,
            'occurred_at': occurred.toIso(),
            'distance_meters': distance,
            'inside_geofence': true,
          },
          'replayed': false,
        };
        keys[item.idempotencyKey] = response;
        _persist();
        return response;
      });

  void _validateLocation(OutboxItem item) {
    if (item.latitude == null || item.longitude == null) {
      throw const ValidationException('GPS required.', code: 'GPS_REQUIRED');
    }
    if (item.isMockLocation) {
      throw const ValidationException(
        'Mock location.',
        code: 'MOCK_LOCATION_DETECTED',
      );
    }
    if ((item.accuracyMeters ?? 0) > 100) {
      throw const ValidationException(
        'Accuracy too low.',
        code: 'GPS_ACCURACY_TOO_LOW',
      );
    }
    final d = haversineMeters(
      item.latitude!,
      item.longitude!,
      DemoData.siteLat,
      DemoData.siteLng,
    );
    if (d > DemoData.siteRadius) {
      throw ValidationException(
        'Outside geofence.',
        code: 'OUTSIDE_GEOFENCE',
        details: {
          'distance_meters': double.parse(d.toStringAsFixed(1)),
          'radius_meters': DemoData.siteRadius,
        },
      );
    }
  }

  Map<String, dynamic> _event(
    OutboxItem item,
    ZonedTime at,
    double distance,
    bool hasSelfie,
  ) => {
    'event_id': _nextId(),
    'occurred_at': at.toIso(),
    'latitude': item.latitude,
    'longitude': item.longitude,
    'accuracy_meters': item.accuracyMeters,
    'distance_meters': double.parse(distance.toStringAsFixed(2)),
    'inside_geofence': true,
    'is_offline_sync': item.isOfflineSync,
    'time_source': item.isOfflineSync ? 'device' : 'server',
    'has_selfie': hasSelfie,
    'selfie_url': null,
  };

  int _nextId() {
    final id = _s['next_id'] as int;
    _s['next_id'] = id + 1;
    return id;
  }

  // ---- corrections --------------------------------------------------------

  List<Map<String, dynamic>> _list(String key) =>
      (_s[key] as List).cast<Map<String, dynamic>>();

  Future<List<Map<String, dynamic>>> corrections() =>
      _call(() => _list('corrections').reversed.toList());

  Future<Map<String, dynamic>> createCorrection({
    required DateTime workDate,
    ZonedTime? checkIn,
    ZonedTime? checkOut,
    required String reason,
  }) => _call(write: true, () {
    final errors = <String, List<String>>{};
    if (checkIn == null && checkOut == null) {
      errors['requested_check_in_at'] = [
        'Provide a check-in or check-out time.',
      ];
    }
    if (reason.trim().length < 5) {
      errors['reason'] = ['The reason must be at least 5 characters.'];
    }
    if (errors.isNotEmpty) {
      throw ValidationException(
        'The given data was invalid.',
        code: 'VALIDATION_ERROR',
        fieldErrors: errors,
      );
    }
    final ageDays = _todayDate.difference(workDate).inDays;
    if (ageDays < 0) {
      throw const ValidationException(
        'Work date cannot be in the future.',
        code: 'INVALID_WORK_DATE',
      );
    }
    if (ageDays > 30) {
      throw const ValidationException(
        'Corrections older than 30 days are not accepted.',
        code: 'CORRECTION_WINDOW_EXPIRED',
      );
    }
    final key = formatDateOnly(workDate);
    final list = _list('corrections');
    if (list.any((c) => c['work_date'] == key && c['status'] == 'PENDING')) {
      throw const ConflictException(
        'A correction is already pending for this date.',
        code: 'CORRECTION_ALREADY_PENDING',
      );
    }
    final created = <String, dynamic>{
      'id': _nextId(),
      'attendance_id': null,
      'work_date': key,
      'requested_check_in_at': checkIn?.toIso(),
      'requested_check_out_at': checkOut?.toIso(),
      'requested_check_in_time': checkIn?.hhmm,
      'requested_check_out_time': checkOut?.hhmm,
      'reason': reason.trim(),
      'status': 'PENDING',
      'reviewed_at': null,
      'review_note': null,
      'created_at': _now.toIso(),
    };
    list.add(created);
    (_s['overrides'] as Map<String, dynamic>)[key] = 'CORRECTION_REQUESTED';
    _persist();
    return created;
  });

  Future<Map<String, dynamic>> cancelCorrection(int id) =>
      _call(write: true, () {
        final c = _list('corrections').firstWhere(
          (c) => c['id'] == id,
          orElse: () => throw const NotFoundException('Request not found.'),
        );
        if (c['status'] != 'PENDING') {
          throw const ConflictException(
            'Only pending requests can be cancelled.',
            code: 'REQUEST_NOT_PENDING',
          );
        }
        c['status'] = 'CANCELLED';
        (_s['overrides'] as Map<String, dynamic>).remove(c['work_date']);
        _persist();
        return c;
      });

  // ---- overtime -----------------------------------------------------------

  Future<List<Map<String, dynamic>>> overtime() =>
      _call(() => _list('overtime').reversed.toList());

  Future<Map<String, dynamic>> createOvertime({
    required DateTime workDate,
    required ZonedTime start,
    required ZonedTime end,
    required String reason,
  }) => _call(write: true, () {
    final errors = <String, List<String>>{};
    if (!end.utc.isAfter(start.utc)) {
      errors['end_at'] = ['The end time must be after the start time.'];
    }
    if (reason.trim().length < 5) {
      errors['reason'] = ['The reason must be at least 5 characters.'];
    }
    if (errors.isNotEmpty) {
      throw ValidationException(
        'The given data was invalid.',
        code: 'VALIDATION_ERROR',
        fieldErrors: errors,
      );
    }
    final minutes = end.utc.difference(start.utc).inMinutes;
    if (minutes > 4 * 60) {
      throw const ValidationException(
        'Overtime cannot exceed 4 hours per request.',
        code: 'OVERTIME_TOO_LONG',
      );
    }
    final list = _list('overtime');
    final overlaps = list.any((o) {
      if (o['status'] == 'REJECTED' || o['status'] == 'CANCELLED') return false;
      final s = ZonedTime.tryParse(o['start_at'])!.utc;
      final e = ZonedTime.tryParse(o['end_at'])!.utc;
      return start.utc.isBefore(e) && end.utc.isAfter(s);
    });
    if (overlaps) {
      throw const ConflictException(
        'This overlaps another overtime request.',
        code: 'OVERTIME_OVERLAP',
      );
    }
    final created = <String, dynamic>{
      'id': _nextId(),
      'work_date': formatDateOnly(workDate),
      'start_at': start.toIso(),
      'end_at': end.toIso(),
      'start_time': start.hhmm,
      'end_time': end.hhmm,
      'requested_minutes': minutes,
      'approved_minutes': null,
      'reason': reason.trim(),
      'status': 'PENDING',
      'reviewed_at': null,
      'review_note': null,
      'created_at': _now.toIso(),
    };
    list.add(created);
    _persist();
    return created;
  });

  Future<Map<String, dynamic>> cancelOvertime(int id) => _call(write: true, () {
    final o = _list('overtime').firstWhere(
      (o) => o['id'] == id,
      orElse: () => throw const NotFoundException('Request not found.'),
    );
    if (o['status'] != 'PENDING') {
      throw const ConflictException(
        'Only pending requests can be cancelled.',
        code: 'REQUEST_NOT_PENDING',
      );
    }
    o['status'] = 'CANCELLED';
    _persist();
    return o;
  });
  // ---- leave ----------------------------------------------------------------

  bool get _onLeaveToday =>
      settings.demoScenario == DemoScenario.onLeave ||
      _leaveBook.approvedOn(_todayDate) != null;

  List<Map<String, dynamic>> get _leaveList {
    final existing = _s['leave_requests'];
    if (existing is List) return existing.cast<Map<String, dynamic>>();
    final seeded = seedDemoLeave(today: _todayDate, now: _now, nextId: _nextId);
    _s['leave_requests'] = seeded;
    _persist();
    return seeded;
  }

  DemoLeaveBook get _leaveBook => DemoLeaveBook(
    requests: _leaveList,
    today: _todayDate,
    now: _now,
    nextId: _nextId,
  );

  Future<List<Map<String, dynamic>>> leaveTypes() => _call(
    () => demoLeaveTypes.map((t) => Map<String, dynamic>.from(t)).toList(),
  );

  Future<List<Map<String, dynamic>>> leaveBalances(int? year) =>
      _call(() => _leaveBook.balances(year ?? _todayDate.year));

  Future<List<Map<String, dynamic>>> leaveRequests(String? status) => _call(
    () =>
        _leaveList
            .where((r) => status == null || r['status'] == status)
            .toList()
          ..sort((a, b) => (b['id'] as int).compareTo(a['id'] as int)),
  );

  Future<Map<String, dynamic>> leaveDetail(int id) =>
      _call(() => _leaveBook.find(id));

  Future<Map<String, dynamic>> leavePreview(Map<String, dynamic> body) =>
      _call(() => _leaveBook.preview(body));

  Future<Map<String, dynamic>> leaveCreate(
    Map<String, dynamic> body,
    bool submit,
  ) => _call(write: true, () {
    final r = _leaveBook.create(body, submit: submit);
    _persist();
    return r;
  });

  Future<Map<String, dynamic>> leaveUpdate(int id, Map<String, dynamic> body) =>
      _call(write: true, () {
        final r = _leaveBook.update(id, body);
        _persist();
        return r;
      });

  Future<Map<String, dynamic>> leaveSubmit(int id) => _call(write: true, () {
    final r = _leaveBook.submit(id);
    _persist();
    return r;
  });

  Future<Map<String, dynamic>> leaveCancel(int id, String? reason) =>
      _call(write: true, () {
        final r = _leaveBook.cancel(id, reason);
        _persist();
        return r;
      });

  // ---- notifications --------------------------------------------------------

  List<Map<String, dynamic>> get _notifications {
    final existing = _s['notifications'];
    if (existing is List) return existing.cast<Map<String, dynamic>>();
    final seeded = seedDemoNotifications(leave: _leaveList, now: _now);
    _s['notifications'] = seeded;
    _persist();
    return seeded;
  }

  Future<Map<String, dynamic>> notifications(int page) => _call(() {
    const perPage = 20;
    final all = [..._notifications]
      ..sort((a, b) => (b['id'] as int).compareTo(a['id'] as int));
    final from = (page - 1) * perPage;
    return {
      'items': all.skip(from).take(perPage).toList(),
      'unread_count': all.where((n) => n['is_read'] != true).length,
      'last_page': (all.length / perPage).ceil().clamp(1, 9999),
    };
  });

  Future<int> unreadCount() =>
      _call(() => _notifications.where((n) => n['is_read'] != true).length);

  Future<void> markRead(int id) => _call(() {
    for (final n in _notifications.where((n) => n['id'] == id)) {
      n
        ..['is_read'] = true
        ..['read_at'] = _now.toIso();
    }
    _persist();
  });

  Future<int> markAllRead() => _call(() {
    var marked = 0;
    for (final n in _notifications.where((n) => n['is_read'] != true)) {
      n
        ..['is_read'] = true
        ..['read_at'] = _now.toIso();
      marked++;
    }
    _persist();
    return marked;
  });
  // ---- payslips & documents -------------------------------------------------

  Future<Map<String, dynamic>> payslips(int? year, int page) => _call(() {
    final all = demoPayslips(_todayDate, _now)
        .where(
          (p) =>
              year == null ||
              (p['period'] as Map)['start_date'].toString().startsWith('$year'),
        )
        .toList();
    return _page(all, page);
  });

  Future<Map<String, dynamic>> payslipDetail(int id) => _call(
    () => demoPayslips(_todayDate, _now).firstWhere(
      (p) => p['id'] == id,
      orElse: () => throw const NotFoundException(
        'The requested resource was not found.',
        code: 'NOT_FOUND',
      ),
    ),
  );

  Future<Uint8List> payslipPdf(int id) async {
    final p = await payslipDetail(id);
    final item = p['item'] as Map<String, dynamic>;
    List<String> rows(String key) => [
      for (final l in (item[key] as List))
        '  ${(l as Map)['name']}: IDR ${l['amount']}${l['description'] == null ? '' : '  (${l['description']})'}',
    ];
    return buildSimplePdf(
      title: 'Payslip ${p['payslip_number']}',
      lines: [
        (p['company'] as Map)['legal_name'] as String,
        'Period: ${(p['period'] as Map)['name']}',
        'Employee: ${(item['employee'] as Map)['full_name']} (EMP-0012)',
        '',
        'Earnings',
        ...rows('earnings'),
        '',
        'Deductions',
        ...rows('deductions'),
        '',
        'Gross pay: IDR ${p['gross_pay']}',
        'Net pay: IDR ${p['net_pay']}',
        '',
        'Demo document generated on this device.',
      ],
    );
  }

  List<Map<String, dynamic>> get _documents => demoDocuments(_todayDate, _now);

  Future<List<Map<String, dynamic>>> documentTypes() => _call(
    () => demoDocumentTypes.map((t) => Map<String, dynamic>.from(t)).toList(),
  );

  Future<Map<String, dynamic>> documents({
    int? typeId,
    String? status,
    int page = 1,
  }) => _call(() {
    final list = _documents.where((d) {
      if (typeId != null && (d['document_type'] as Map)['id'] != typeId) {
        return false;
      }
      if (status != null && d['status'] != status.toUpperCase()) return false;
      return true;
    }).toList();
    return _page(list, page);
  });

  Future<Map<String, dynamic>> documentDetail(int id) => _call(
    () => _documents.firstWhere(
      (d) => d['id'] == id,
      orElse: () => throw const NotFoundException(
        'The requested resource was not found.',
        code: 'NOT_FOUND',
      ),
    ),
  );

  Future<Uint8List> documentBytes(int id) async {
    final d = await documentDetail(id);
    return buildSimplePdf(
      title: d['title'] as String,
      lines: [
        '${(d['document_type'] as Map)['name']}',
        'Employee: Michael Carter (EMP-0012)',
        if (d['expiry_date'] != null) 'Expires: ${d['expiry_date']}',
        '',
        'Sample document generated by PeopleOS Demo mode.',
      ],
    );
  }

  Map<String, dynamic> _page(List<Map<String, dynamic>> all, int page) {
    const perPage = 20;
    return {
      'data': all.skip((page - 1) * perPage).take(perPage).toList(),
      'page': page,
      'last_page': (all.length / perPage).ceil().clamp(1, 9999),
      'total': all.length,
    };
  }
}
