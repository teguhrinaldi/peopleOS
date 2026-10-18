import 'package:peopleos/core/utils/zoned_time.dart';

/// Static content of the Demo company. The demo "server" (see
/// `demo_backend.dart`) speaks the same JSON shapes as the Laravel API, so the
/// UI and models stay identical in both modes.
class DemoData {
  const DemoData._();

  static const email = 'employee@peopleos.test';
  static const password = 'password';

  static const siteName = 'Head Office';
  static const siteLat = -6.9218;
  static const siteLng = 107.6071;
  static const siteRadius = 150.0;

  static const startMinutes = 8 * 60;
  static const endMinutes = 17 * 60;
  static const graceMinutes = 10;
  static const breakMinutes = 60;

  static Map<String, dynamic> employee() => {
    'id': 12,
    'employee_number': 'EMP-0012',
    'full_name': 'Michael Carter',
    'photo_url': null,
    'gender': 'male',
    'date_of_birth': '1990-11-18',
    'phone': '+62 812 3456 7890',
    'email': email,
    'address': 'Jl. Merdeka No. 12, Bandung',
    'join_date': '2022-01-15',
    'employment_type': 'permanent',
    'status': 'ACTIVE',
    'terminated_at': null,
    'department': {'id': 4, 'name': 'Marketing'},
    'position': {'id': 5, 'name': 'Marketing Staff'},
    'branch': {'id': 1, 'name': 'Bandung Head Office'},
    'manager': {'id': 4, 'full_name': 'Amanda Clark'},
    'work_location': {'id': 1, 'name': siteName},
    'shift': {
      'id': 1,
      'name': 'Regular Shift',
      'start_time': '08:00',
      'end_time': '17:00',
    },
  };

  static Map<String, dynamic> session(String timezone) => {
    'user': {'id': 4, 'name': 'Michael Carter', 'email': email},
    'employee': employee(),
    'permissions': [
      'attendance.self',
      'correction.request',
      'overtime.request',
    ],
    'company': {'id': 1, 'name': 'PeopleOS Demo Company', 'timezone': timezone},
  };

  static Map<String, dynamic> schedule({
    required DateTime workDate,
    required Duration offset,
    required bool workingDay,
    required bool requireSelfie,
  }) {
    ZonedTime at(int minutes) => ZonedTime(
      DateTime.utc(
        workDate.year,
        workDate.month,
        workDate.day,
      ).add(Duration(minutes: minutes) - offset),
      offset,
    );
    return {
      'id': null,
      'work_date': formatDateOnly(workDate),
      'day_type': workingDay ? 'workday' : 'rest_day',
      'is_working_day': workingDay,
      'shift_name': 'Regular Shift',
      'start_time': '08:00',
      'end_time': '17:00',
      'expected_start_at': at(startMinutes).toIso(),
      'expected_end_at': at(endMinutes).toIso(),
      'crosses_midnight': false,
      'grace_late_minutes': graceMinutes,
      'break_minutes': breakMinutes,
      'expected_working_minutes': 480,
      'location': {
        'name': siteName,
        'latitude': siteLat,
        'longitude': siteLng,
        'radius_meters': siteRadius,
      },
      'policy': {
        'require_gps': true,
        'geofence_mode': 'enforce',
        'require_selfie': requireSelfie,
        'max_gps_accuracy_meters': 100,
        'offline_max_age_minutes': 720,
      },
    };
  }
}

/// Deterministic 30+ day history ending yesterday (weekends are rest days).
List<Map<String, dynamic>> generateDemoHistory({
  required DateTime today,
  required Duration offset,
  int days = 45,
}) {
  final out = <Map<String, dynamic>>[];
  for (var back = 1; back <= days; back++) {
    final date = DateTime(today.year, today.month, today.day - back);
    if (date.weekday >= DateTime.saturday) continue;
    final h = (date.day * 31 + date.month * 17 + date.year) % 100;
    final late = h % 5 == 0;
    final inMin = late ? 8 * 60 + 11 + (h % 14) : 7 * 60 + 48 + (h % 11);
    final outMin = 17 * 60 + (h * 7 % 45);
    final corrected = h % 23 == 0;
    final offline = h % 17 == 0;

    ZonedTime at(int minutes) => ZonedTime(
      DateTime.utc(
        date.year,
        date.month,
        date.day,
      ).add(Duration(minutes: minutes) - offset),
      offset,
    );

    Map<String, dynamic> event(ZonedTime t, int id) => {
      'event_id': id,
      'occurred_at': t.toIso(),
      'latitude': DemoData.siteLat + (h % 7) * 0.00006,
      'longitude': DemoData.siteLng + (h % 5) * 0.00006,
      'accuracy_meters': 8 + (h % 12),
      'distance_meters': 12.0 + (h % 60),
      'inside_geofence': true,
      'is_offline_sync': offline,
      'time_source': offline ? 'device' : 'server',
      'has_selfie': false,
      'selfie_url': null,
    };

    final id = 5000 + back;
    out.add({
      'id': id,
      'employee_id': 12,
      'work_date': formatDateOnly(date),
      'status': corrected ? 'CORRECTED' : 'CHECKED_OUT',
      'needs_review': false,
      'check_in_at': at(inMin).toIso(),
      'check_out_at': at(outMin).toIso(),
      'check_in_time': at(inMin).hhmm,
      'check_out_time': at(outMin).hhmm,
      'worked_minutes': outMin - inMin - DemoData.breakMinutes,
      'late_minutes': late ? inMin - DemoData.startMinutes : 0,
      'early_leave_minutes': 0,
      'is_late': late,
      'is_early_leave': false,
      'review_note': corrected
          ? 'Check-out time corrected after manager approval.'
          : null,
      'shift_name': 'Regular Shift',
      'location_name': DemoData.siteName,
      'day_type': 'workday',
      'expected_start_time': '08:00',
      'expected_end_time': '17:00',
      'check_in': event(at(inMin), id * 10),
      'check_out': event(at(outMin), id * 10 + 1),
    });
  }
  return out;
}
