/// JSON fixtures in the exact shapes documented in docs/api.md.
library;

Map<String, dynamic> employeeJson({String status = 'ACTIVE'}) => {
  'id': 6,
  'employee_number': 'EMP-0012',
  'full_name': 'Michael Carter',
  'photo_url': null,
  'gender': 'male',
  'date_of_birth': '1990-11-18',
  'phone': '+62 812 0000 0000',
  'email': 'employee@peopleos.test',
  'address': 'Jl. Merdeka 12, Bandung',
  'join_date': '2022-01-15',
  'employment_type': 'permanent',
  'status': status,
  'terminated_at': null,
  'department': {'id': 4, 'name': 'Marketing'},
  'position': {'id': 5, 'name': 'Marketing Staff'},
  'branch': {'id': 1, 'name': 'Bandung Head Office'},
  'manager': {'id': 4, 'full_name': 'Amanda Clark'},
  'work_location': {'id': 1, 'name': 'Head Office'},
  'shift': {
    'id': 1,
    'name': 'Regular Shift',
    'start_time': '08:00',
    'end_time': '17:00',
  },
};

Map<String, dynamic> loginJson({
  String status = 'ACTIVE',
  String? serverLocale,
}) => {
  'preferences': {
    'locale': serverLocale,
    'effective_locale': serverLocale ?? 'en',
    'default_locale': 'en',
  },
  'user': {
    'id': 4,
    'name': 'Michael Carter',
    'email': 'employee@peopleos.test',
  },
  'employee': employeeJson(status: status),
  'permissions': ['attendance.self', 'correction.request'],
  'company': {
    'id': 1,
    'name': 'PeopleOS Demo Company',
    'timezone': 'Asia/Jakarta',
  },
  'token': '1|secret',
  'token_type': 'Bearer',
  'expires_at': '2026-10-30T07:35:04+00:00',
};

Map<String, dynamic> scheduleJson({bool selfie = false, bool working = true}) =>
    {
      'id': null,
      'work_date': '2026-09-30',
      'day_type': working ? 'workday' : 'rest_day',
      'is_working_day': working,
      'shift_name': 'Regular Shift',
      'start_time': '08:00',
      'end_time': '17:00',
      'expected_start_at': '2026-09-30T08:00:00+07:00',
      'expected_end_at': '2026-09-30T17:00:00+07:00',
      'crosses_midnight': false,
      'grace_late_minutes': 10,
      'break_minutes': 60,
      'expected_working_minutes': 480,
      'location': {
        'name': 'Head Office',
        'latitude': -6.9218,
        'longitude': 107.6071,
        'radius_meters': 150,
      },
      'policy': {
        'require_gps': true,
        'geofence_mode': 'enforce',
        'require_selfie': selfie,
        'max_gps_accuracy_meters': 100,
        'offline_max_age_minutes': 720,
      },
    };

Map<String, dynamic> attendanceJson({
  String status = 'CHECKED_IN',
  bool checkedOut = false,
  bool late = false,
}) => {
  'id': 228,
  'employee_id': 6,
  'work_date': '2026-09-30',
  'status': status,
  'needs_review': false,
  'check_in_at': '2026-09-30T08:04:00+07:00',
  'check_out_at': checkedOut ? '2026-09-30T17:10:00+07:00' : null,
  'check_in_time': '08:04',
  'check_out_time': checkedOut ? '17:10' : null,
  'worked_minutes': checkedOut ? 486 : 0,
  'late_minutes': late ? 22 : 0,
  'early_leave_minutes': 0,
  'is_late': late,
  'is_early_leave': false,
  'review_note': null,
  'shift_name': 'Regular Shift',
  'location_name': 'Head Office',
  'day_type': 'workday',
  'expected_start_time': '08:00',
  'expected_end_time': '17:00',
  'check_in': {
    'event_id': 448,
    'occurred_at': '2026-09-30T08:04:00+07:00',
    'latitude': -6.9218,
    'longitude': 107.6071,
    'accuracy_meters': 12,
    'distance_meters': 15.67,
    'inside_geofence': true,
    'is_offline_sync': false,
    'time_source': 'server',
    'has_selfie': false,
    'selfie_url': null,
  },
  'check_out': null,
};

Map<String, dynamic> todayJson({
  String state = 'NOT_CHECKED_IN',
  String? blockedReason,
  bool selfie = false,
  bool schedule = true,
  bool working = true,
  bool late = false,
  String serverTime = '2026-09-30T14:35:11+07:00',
}) {
  final open = state == 'CHECKED_IN' || state == 'PENDING_REVIEW_OPEN';
  final done = state == 'CHECKED_OUT';
  return {
    'server_time': serverTime,
    'timezone': 'Asia/Jakarta',
    'today': '2026-09-30',
    'work_date': '2026-09-30',
    'state': state,
    'blocked_reason': blockedReason,
    'can_check_in': state == 'NOT_CHECKED_IN',
    'can_check_out': open,
    'elapsed_minutes': open ? 391 : null,
    'schedule': schedule
        ? scheduleJson(selfie: selfie, working: working)
        : null,
    'attendance': open || done
        ? attendanceJson(
            status: done ? 'CHECKED_OUT' : 'CHECKED_IN',
            checkedOut: done,
            late: late,
          )
        : null,
    'holiday': null,
    'pending_correction': null,
  };
}

Map<String, dynamic> checkResultJson({
  bool replayed = false,
  bool out = false,
}) => {
  'attendance': attendanceJson(
    status: out ? 'CHECKED_OUT' : 'CHECKED_IN',
    checkedOut: out,
  ),
  'event': {
    'id': 448,
    'type': out ? 'CHECK_OUT' : 'CHECK_IN',
    'occurred_at': '2026-09-30T08:04:00+07:00',
    'distance_meters': 15.67,
    'inside_geofence': true,
  },
  'replayed': replayed,
};

Map<String, dynamic> correctionJson({String status = 'PENDING'}) => {
  'id': 12,
  'employee': {
    'id': 6,
    'full_name': 'Michael Carter',
    'employee_number': 'EMP-0012',
  },
  'attendance_id': 200,
  'work_date': '2026-09-25',
  'requested_check_in_at': null,
  'requested_check_out_at': '2026-09-25T17:05:00+07:00',
  'requested_check_in_time': null,
  'requested_check_out_time': '17:05',
  'reason': 'Forgot to check out',
  'status': status,
  'reviewed_at': null,
  'review_note': null,
  'created_at': '2026-09-26T09:00:00+07:00',
};

Map<String, dynamic> overtimeJson({String status = 'PENDING'}) => {
  'id': 7,
  'employee': {
    'id': 6,
    'full_name': 'Michael Carter',
    'employee_number': 'EMP-0012',
  },
  'work_date': '2026-09-29',
  'start_at': '2026-09-29T17:00:00+07:00',
  'end_at': '2026-09-29T19:00:00+07:00',
  'start_time': '17:00',
  'end_time': '19:00',
  'requested_minutes': 120,
  'approved_minutes': null,
  'reason': 'Release prep',
  'status': status,
  'reviewed_at': null,
  'review_note': null,
  'created_at': '2026-09-29T15:00:00+07:00',
};

Map<String, dynamic> errorJson(
  String code,
  String message, [
  Map<String, dynamic>? details,
]) => {
  'success': false,
  'error': {'code': code, 'message': message, 'details': details ?? {}},
};

Map<String, dynamic> okJson(Object? data, {Map<String, dynamic>? meta}) => {
  'success': true,
  'data': data,
  'meta': ?meta,
};

Map<String, dynamic> leaveTypeJson({
  int id = 1,
  String code = 'ANNUAL',
  String name = 'Annual Leave',
  bool unlimited = false,
  bool halfDay = true,
  bool requiresReason = false,
  int notice = 3,
}) => {
  'id': id,
  'code': code,
  'name': name,
  'description': 'Paid holiday entitlement.',
  'is_paid': true,
  'color': id == 1 ? 'blue' : 'orange',
  'is_active': true,
  'policy': {
    'id': id,
    'employment_type': null,
    'annual_allowance': unlimited ? null : '12.0',
    'unlimited': unlimited,
    'prorate_first_year': true,
    'carry_over_max': '6.0',
    'allow_negative': false,
    'max_negative_days': '0.0',
    'allow_half_day': halfDay,
    'allow_overlap': false,
    'min_notice_days': notice,
    'max_past_days': unlimited ? 7 : 0,
    'max_consecutive_days': null,
    'requires_reason': requiresReason,
  },
};

Map<String, dynamic> leaveBalanceJson({int id = 1, bool unlimited = false}) => {
  'id': id,
  'employee_id': 6,
  'leave_type': {
    'id': id,
    'code': id == 1 ? 'ANNUAL' : 'SICK',
    'name': id == 1 ? 'Annual Leave' : 'Sick Leave',
    'color': id == 1 ? 'blue' : 'orange',
    'is_paid': true,
  },
  'year': 2026,
  'unlimited': unlimited,
  'allowance': unlimited ? null : '12.0',
  'carried_over': '0.0',
  'adjustment': '0.0',
  'entitlement': unlimited ? null : '12.0',
  'used': unlimited ? '1.0' : '3.0',
  'reserved': unlimited ? '0.0' : '3.0',
  'available': unlimited ? null : '6.0',
};

Map<String, dynamic> leaveRequestJson({
  int id = 7,
  String status = 'PENDING',
  String start = '2026-10-26',
  String end = '2026-10-28',
  String days = '3.0',
  String? halfDay,
  String? reviewNote,
}) => {
  'id': id,
  'employee': {
    'id': 6,
    'full_name': 'Michael Carter',
    'employee_number': 'EMP-0012',
  },
  'leave_type': {
    'id': 1,
    'code': 'ANNUAL',
    'name': 'Annual Leave',
    'color': 'blue',
    'is_paid': true,
  },
  'start_date': start,
  'end_date': end,
  'half_day': halfDay,
  'days': days,
  'reason': "Sister's wedding in Medan",
  'status': status,
  'submitted_at': status == 'DRAFT' ? null : '2026-10-10T00:05:21+07:00',
  'reviewed_at': status == 'APPROVED' || status == 'REJECTED'
      ? '2026-10-11T09:15:00+07:00'
      : null,
  'reviewer': status == 'APPROVED' || status == 'REJECTED'
      ? {'id': 3, 'name': 'Amanda Clark'}
      : null,
  'review_note': reviewNote,
  'cancelled_at': null,
  'cancel_reason': null,
  'created_at': '2026-10-10T00:05:21+07:00',
};

Map<String, dynamic> leavePreviewJson({
  String days = '4.0',
  String? after = '2.0',
}) => {
  'days': days,
  'working_dates': ['2026-11-05', '2026-11-06', '2026-11-09', '2026-11-10'],
  'balance': leaveBalanceJson(),
  'available_after': after,
};

Map<String, dynamic> notificationJson({
  int id = 8,
  String type = 'leave.approved',
  String title = 'Leave approved',
  String body = 'Annual Leave, 25 Aug – 27 Aug 2026 (3.0 day(s)).',
  String entityType = r'App\Models\LeaveRequest',
  int entityId = 7,
  bool read = false,
}) => {
  'id': id,
  'type': type,
  'title': title,
  'body': body,
  'entity_type': entityType,
  'entity_id': entityId,
  'data': <String, dynamic>{},
  'is_read': read,
  'read_at': read ? '2026-10-01T10:00:00+07:00' : null,
  'created_at': '2026-10-09T09:15:00+07:00',
};
