import 'package:intl/intl.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/features/demo/demo_leave.dart';
import 'package:peopleos/features/demo/demo_payroll.dart';

/// Demo leave history, relative to "today" so the demo always looks current:
/// an approved trip ~6 weeks ago, a pending request in ~2-3 weeks and a
/// rejected personal day. Mirrors the Laravel demo seed for Michael Carter.
List<Map<String, dynamic>> seedDemoLeave({
  required DateTime today,
  required ZonedTime now,
  required int Function() nextId,
}) {
  DateTime monday(DateTime d) =>
      DateTime(d.year, d.month, d.day - (d.weekday - 1));
  final pastMon = monday(today.subtract(const Duration(days: 42)));
  final nextMon = monday(today.add(const Duration(days: 21)));
  final rejectedDay = monday(today.subtract(const Duration(days: 14)))
      .add(const Duration(days: 4)); // a Friday

  ZonedTime at(DateTime d, int h) => ZonedTime.fromWall(d, h, 0, now.offset);

  Map<String, dynamic> request({
    required int typeId,
    required DateTime start,
    required DateTime end,
    required String status,
    required String reason,
    required DateTime createdOn,
    String? reviewNote,
  }) {
    final type = demoLeaveTypeById(typeId);
    final dates = demoWorkingDates(start, end);
    final reviewed = status == 'APPROVED' || status == 'REJECTED';
    return {
      'id': nextId(),
      'employee': {
        'id': 12,
        'full_name': 'Michael Carter',
        'employee_number': 'EMP-0012',
      },
      'leave_type': {
        'id': type['id'],
        'code': type['code'],
        'name': type['name'],
        'color': type['color'],
        'is_paid': type['is_paid'],
      },
      'start_date': formatDateOnly(start),
      'end_date': formatDateOnly(end),
      'half_day': null,
      'days': dates.length.toStringAsFixed(1),
      'reason': reason,
      'status': status,
      'submitted_at': at(createdOn, 9).toIso(),
      'reviewed_at': reviewed
          ? at(createdOn.add(const Duration(days: 1)), 10).toIso()
          : null,
      'reviewer': reviewed ? {'id': 3, 'name': 'Amanda Clark'} : null,
      'review_note': reviewNote,
      'cancelled_at': null,
      'cancel_reason': null,
      'created_at': at(createdOn, 9).toIso(),
      'working_dates': dates.map(formatDateOnly).toList(),
    };
  }

  return [
    request(
      typeId: 1,
      start: pastMon.add(const Duration(days: 1)),
      end: pastMon.add(const Duration(days: 3)),
      status: 'APPROVED',
      reason: 'Family trip to Yogyakarta',
      createdOn: pastMon.subtract(const Duration(days: 7)),
      reviewNote: 'Approved, enjoy the trip.',
    ),
    request(
      typeId: 3,
      start: rejectedDay,
      end: rejectedDay,
      status: 'REJECTED',
      reason: 'Moving to a new apartment',
      createdOn: rejectedDay.subtract(const Duration(days: 3)),
      reviewNote: 'Quarter-end closing that day. Please pick another date.',
    ),
    request(
      typeId: 1,
      start: nextMon,
      end: nextMon.add(const Duration(days: 2)),
      status: 'PENDING',
      reason: "Sister's wedding in Medan",
      createdOn: today,
    ),
  ];
}

/// In-app notifications that match the seeded demo data.
List<Map<String, dynamic>> seedDemoNotifications({
  required List<Map<String, dynamic>> leave,
  required ZonedTime now,
}) {
  var id = 0;
  Map<String, dynamic> n({
    required String type,
    required String title,
    required String body,
    required String entityType,
    required int entityId,
    required Duration ago,
    bool read = false,
  }) {
    final at = ZonedTime(now.utc.subtract(ago), now.offset).toIso();
    return {
      'id': ++id,
      'type': type,
      'title': title,
      'body': body,
      'entity_type': entityType,
      'entity_id': entityId,
      'data': <String, dynamic>{},
      'is_read': read,
      'read_at': read ? at : null,
      'created_at': at,
    };
  }

  String day(Object? iso) =>
      DateFormat('d MMM yyyy').format(parseDateOnly(iso)!);
  final latestPayslip = demoPayslips(
    DateTime(now.wall.year, now.wall.month, now.wall.day),
    now,
  ).first;
  final approved = leave.firstWhere((r) => r['status'] == 'APPROVED');
  final rejected = leave.firstWhere((r) => r['status'] == 'REJECTED');
  return [
    n(
      type: 'overtime.approved',
      title: 'Overtime approved',
      body: 'Your overtime request was approved for 2 hours.',
      entityType: r'App\Models\OvertimeRequest',
      entityId: 81,
      ago: const Duration(days: 7),
      read: true,
    ),
    n(
      type: 'leave.approved',
      title: 'Leave approved',
      body:
          'Annual Leave, ${day(approved['start_date'])} to ${day(approved['end_date'])} '
          '(${approved['days']} days).',
      entityType: r'App\Models\LeaveRequest',
      entityId: approved['id'] as int,
      ago: const Duration(days: 5),
      read: true,
    ),
    n(
      type: 'attendance_correction.approved',
      title: 'Attendance correction approved',
      body: 'Your correction request has been approved.',
      entityType: r'App\Models\AttendanceCorrection',
      entityId: 91,
      ago: const Duration(days: 1),
    ),
    n(
      type: 'payslip.available',
      title: 'Payslip available',
      body:
          '${(latestPayslip['period'] as Map)['name']} payslip is ready to view.',
      entityType: r'AppModelsPayslip',
      entityId: latestPayslip['id'] as int,
      ago: const Duration(days: 2),
    ),
    n(
      type: 'document.expiring',
      title: 'Document expiring',
      body: 'Your National ID Card expires soon.',
      entityType: r'AppModelsDocument',
      entityId: 18,
      ago: const Duration(hours: 20),
    ),
    n(
      type: 'leave.rejected',
      title: 'Leave rejected',
      body:
          'Personal Leave on ${day(rejected['start_date'])}: ${rejected['review_note']}',
      entityType: r'App\Models\LeaveRequest',
      entityId: rejected['id'] as int,
      ago: const Duration(hours: 3),
    ),
  ];
}
