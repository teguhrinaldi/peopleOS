import 'package:flutter/foundation.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';

@immutable
class WorkSite {
  const WorkSite({
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.radiusMeters,
  });

  final String name;
  final double latitude;
  final double longitude;
  final double radiusMeters;

  static WorkSite? from(JsonReader? j) {
    if (j == null) return null;
    final lat = j.doubleOrNull('latitude');
    final lng = j.doubleOrNull('longitude');
    if (lat == null || lng == null) return null;
    return WorkSite(
      name: j.str('name') ?? 'Work site',
      latitude: lat,
      longitude: lng,
      radiusMeters: j.doubleOrNull('radius_meters') ?? 100,
    );
  }
}

@immutable
class AttendancePolicy {
  const AttendancePolicy({
    this.requireGps = true,
    this.geofenceMode = 'enforce',
    this.requireSelfie = false,
    this.maxGpsAccuracyMeters = 100,
    this.offlineMaxAgeMinutes = 720,
  });

  final bool requireGps;
  final String geofenceMode;
  final bool requireSelfie;
  final int maxGpsAccuracyMeters;
  final int offlineMaxAgeMinutes;

  factory AttendancePolicy.from(JsonReader? j) {
    if (j == null) return const AttendancePolicy();
    return AttendancePolicy(
      requireGps: j.boolOr('require_gps', true),
      geofenceMode: j.str('geofence_mode') ?? 'enforce',
      requireSelfie: j.boolOr('require_selfie', false),
      maxGpsAccuracyMeters: j.intOr('max_gps_accuracy_meters', 100),
      offlineMaxAgeMinutes: j.intOr('offline_max_age_minutes', 720),
    );
  }
}

@immutable
class Schedule {
  const Schedule({
    required this.workDate,
    required this.dayType,
    required this.isWorkingDay,
    required this.shiftName,
    required this.startTime,
    required this.endTime,
    required this.crossesMidnight,
    required this.graceLateMinutes,
    required this.breakMinutes,
    required this.expectedWorkingMinutes,
    required this.policy,
    this.location,
    this.expectedStartAt,
    this.expectedEndAt,
  });

  final DateTime? workDate;
  final String dayType;
  final bool isWorkingDay;
  final String? shiftName;
  final String? startTime;
  final String? endTime;
  final bool crossesMidnight;
  final int graceLateMinutes;
  final int breakMinutes;
  final int expectedWorkingMinutes;
  final WorkSite? location;
  final AttendancePolicy policy;
  final ZonedTime? expectedStartAt;
  final ZonedTime? expectedEndAt;

  String get timeRange =>
      (startTime == null || endTime == null) ? '' : '$startTime - $endTime';

  factory Schedule.fromJson(JsonReader j) => Schedule(
    workDate: j.date('work_date'),
    dayType: j.str('day_type') ?? 'workday',
    isWorkingDay: j.boolOr('is_working_day', true),
    shiftName: j.str('shift_name'),
    startTime: j.str('start_time'),
    endTime: j.str('end_time'),
    crossesMidnight: j.boolOr('crosses_midnight', false),
    graceLateMinutes: j.intOr('grace_late_minutes', 0),
    breakMinutes: j.intOr('break_minutes', 0),
    expectedWorkingMinutes: j.intOr('expected_working_minutes', 0),
    location: WorkSite.from(j.obj('location')),
    policy: AttendancePolicy.from(j.obj('policy')),
    expectedStartAt: j.time('expected_start_at'),
    expectedEndAt: j.time('expected_end_at'),
  );
}

/// One check-in or check-out event inside an [AttendanceRecord].
@immutable
class AttendanceEvent {
  const AttendanceEvent({
    this.eventId,
    this.occurredAt,
    this.latitude,
    this.longitude,
    this.accuracyMeters,
    this.distanceMeters,
    this.insideGeofence,
    this.isOfflineSync = false,
    this.hasSelfie = false,
    this.selfieUrl,
  });

  final int? eventId;
  final ZonedTime? occurredAt;
  final double? latitude;
  final double? longitude;
  final double? accuracyMeters;
  final double? distanceMeters;
  final bool? insideGeofence;
  final bool isOfflineSync;
  final bool hasSelfie;
  final String? selfieUrl;

  static AttendanceEvent? from(JsonReader? j) {
    if (j == null) return null;
    return AttendanceEvent(
      eventId: j.intOrNull('event_id'),
      occurredAt: j.time('occurred_at'),
      latitude: j.doubleOrNull('latitude'),
      longitude: j.doubleOrNull('longitude'),
      accuracyMeters: j.doubleOrNull('accuracy_meters'),
      distanceMeters: j.doubleOrNull('distance_meters'),
      insideGeofence: j.map['inside_geofence'] as bool?,
      isOfflineSync: j.boolOr('is_offline_sync', false),
      hasSelfie: j.boolOr('has_selfie', false),
      selfieUrl: j.str('selfie_url'),
    );
  }
}

@immutable
class AttendanceRecord {
  const AttendanceRecord({
    required this.id,
    required this.workDate,
    required this.status,
    this.needsReview = false,
    this.checkInAt,
    this.checkOutAt,
    this.checkInTime,
    this.checkOutTime,
    this.workedMinutes = 0,
    this.lateMinutes = 0,
    this.earlyLeaveMinutes = 0,
    this.isLate = false,
    this.isEarlyLeave = false,
    this.reviewNote,
    this.shiftName,
    this.locationName,
    this.dayType,
    this.expectedStartTime,
    this.expectedEndTime,
    this.checkIn,
    this.checkOut,
  });

  final int id;
  final DateTime workDate;
  final String status;
  final bool needsReview;
  final ZonedTime? checkInAt;
  final ZonedTime? checkOutAt;
  final String? checkInTime;
  final String? checkOutTime;
  final int workedMinutes;
  final int lateMinutes;
  final int earlyLeaveMinutes;
  final bool isLate;
  final bool isEarlyLeave;
  final String? reviewNote;
  final String? shiftName;
  final String? locationName;
  final String? dayType;
  final String? expectedStartTime;
  final String? expectedEndTime;
  final AttendanceEvent? checkIn;
  final AttendanceEvent? checkOut;

  bool get isOpen => checkInAt != null && checkOutAt == null;

  factory AttendanceRecord.fromJson(JsonReader j) => AttendanceRecord(
    id: j.intOrNull('id') ?? 0,
    workDate: j.date('work_date') ?? DateTime.now(),
    status: j.str('status') ?? 'PENDING',
    needsReview: j.boolOr('needs_review', false),
    checkInAt: j.time('check_in_at'),
    checkOutAt: j.time('check_out_at'),
    checkInTime: j.str('check_in_time'),
    checkOutTime: j.str('check_out_time'),
    workedMinutes: j.intOr('worked_minutes', 0),
    lateMinutes: j.intOr('late_minutes', 0),
    earlyLeaveMinutes: j.intOr('early_leave_minutes', 0),
    isLate: j.boolOr('is_late', false),
    isEarlyLeave: j.boolOr('is_early_leave', false),
    reviewNote: j.str('review_note'),
    shiftName: j.str('shift_name'),
    locationName: j.str('location_name'),
    dayType: j.str('day_type'),
    expectedStartTime: j.str('expected_start_time'),
    expectedEndTime: j.str('expected_end_time'),
    checkIn: AttendanceEvent.from(j.obj('check_in')),
    checkOut: AttendanceEvent.from(j.obj('check_out')),
  );
}

enum TodayState {
  notCheckedIn,
  checkedIn,
  checkedOut,
  pendingReviewOpen,
  pendingReview,
  correctionPending,
  rejected,
  notWorkingDay,
  noSchedule,
  onLeave,
  blocked;

  static TodayState parse(String? raw) {
    switch (raw) {
      case 'NOT_CHECKED_IN':
        return notCheckedIn;
      case 'CHECKED_IN':
        return checkedIn;
      case 'CHECKED_OUT':
        return checkedOut;
      case 'PENDING_REVIEW_OPEN':
        return pendingReviewOpen;
      case 'PENDING_REVIEW':
        return pendingReview;
      case 'CORRECTION_PENDING':
        return correctionPending;
      case 'REJECTED':
        return rejected;
      case 'NOT_WORKING_DAY':
        return notWorkingDay;
      case 'BLOCKED':
        return blocked;
      case 'ON_LEAVE':
        return onLeave;
    }
    return noSchedule;
  }
}

/// Read model for the Home screen (`GET /attendance/today`).
@immutable
class TodayAttendance {
  const TodayAttendance({
    required this.serverTime,
    required this.timezone,
    required this.today,
    required this.state,
    required this.canCheckIn,
    required this.canCheckOut,
    this.workDate,
    this.blockedReason,
    this.elapsedMinutes,
    this.schedule,
    this.attendance,
    this.holidayName,
    this.hasPendingCorrection = false,
    this.isStale = false,
  });

  final ZonedTime serverTime;
  final String timezone;
  final DateTime today;
  final DateTime? workDate;
  final TodayState state;
  final String? blockedReason;
  final bool canCheckIn;
  final bool canCheckOut;
  final int? elapsedMinutes;
  final Schedule? schedule;
  final AttendanceRecord? attendance;
  final String? holidayName;
  final bool hasPendingCorrection;

  /// True when served from the local cache because the network failed.
  final bool isStale;

  TodayAttendance copyWith({
    TodayState? state,
    bool? canCheckIn,
    bool? canCheckOut,
    bool? isStale,
  }) => TodayAttendance(
    serverTime: serverTime,
    timezone: timezone,
    today: today,
    workDate: workDate,
    state: state ?? this.state,
    blockedReason: blockedReason,
    canCheckIn: canCheckIn ?? this.canCheckIn,
    canCheckOut: canCheckOut ?? this.canCheckOut,
    elapsedMinutes: elapsedMinutes,
    schedule: schedule,
    attendance: attendance,
    holidayName: holidayName,
    hasPendingCorrection: hasPendingCorrection,
    isStale: isStale ?? this.isStale,
  );

  /// A cached read model must not claim yesterday's state today. When the
  /// company-local date moved on, fall back to an open working day so the
  /// employee can still record attendance offline (the server re-validates).
  TodayAttendance asStaleAt(DateTime nowUtc) {
    final w = nowUtc.add(serverTime.offset);
    final sameDay =
        w.year == today.year && w.month == today.month && w.day == today.day;
    if (sameDay) return copyWith(isStale: true);
    final working = schedule?.isWorkingDay ?? false;
    return TodayAttendance(
      serverTime: serverTime,
      timezone: timezone,
      today: DateTime(w.year, w.month, w.day),
      state: working ? TodayState.notCheckedIn : TodayState.noSchedule,
      canCheckIn: working,
      canCheckOut: false,
      schedule: schedule,
      isStale: true,
    );
  }

  factory TodayAttendance.fromJson(JsonReader j) {
    final schedule = j.obj('schedule');
    final attendance = j.obj('attendance');
    final holiday = j.obj('holiday');
    final serverTime =
        j.time('server_time') ??
        ZonedTime(DateTime.now().toUtc(), Duration.zero);
    return TodayAttendance(
      serverTime: serverTime,
      timezone: j.str('timezone') ?? 'Asia/Jakarta',
      today: j.date('today') ?? serverTime.wall,
      workDate: j.date('work_date'),
      state: TodayState.parse(j.str('state')),
      blockedReason: j.str('blocked_reason'),
      canCheckIn: j.boolOr('can_check_in', false),
      canCheckOut: j.boolOr('can_check_out', false),
      elapsedMinutes: j.intOrNull('elapsed_minutes'),
      schedule: schedule == null ? null : Schedule.fromJson(schedule),
      attendance: attendance == null
          ? null
          : AttendanceRecord.fromJson(attendance),
      holidayName: holiday?.str('name'),
      hasPendingCorrection: j.obj('pending_correction') != null,
    );
  }
}

/// Response of check-in / check-out.
@immutable
class CheckResult {
  const CheckResult({
    required this.attendance,
    this.distanceMeters,
    this.insideGeofence,
    this.replayed = false,
  });

  final AttendanceRecord attendance;
  final double? distanceMeters;
  final bool? insideGeofence;
  final bool replayed;

  factory CheckResult.fromJson(JsonReader j) {
    final event = j.obj('event');
    return CheckResult(
      attendance: AttendanceRecord.fromJson(
        j.obj('attendance') ??
            (throw const UnknownException(
              'Unexpected response shape.',
              'BAD_SHAPE',
            )),
      ),
      distanceMeters: event?.doubleOrNull('distance_meters'),
      insideGeofence: event?.map['inside_geofence'] as bool?,
      replayed: j.boolOr('replayed', false),
    );
  }
}
