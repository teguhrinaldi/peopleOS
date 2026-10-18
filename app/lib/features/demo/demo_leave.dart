import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/zoned_time.dart';

/// Leave types of the demo company: the same examples the Laravel seeders
/// ship (Annual 12, Sick unlimited, Personal 3, Unpaid, Other).
const List<Map<String, dynamic>> demoLeaveTypes = [
  {
    'id': 1,
    'code': 'ANNUAL',
    'name': 'Annual Leave',
    'description': 'Paid holiday entitlement.',
    'is_paid': true,
    'color': 'blue',
    'policy': {
      'annual_allowance': '12.0',
      'unlimited': false,
      'allow_negative': false,
      'allow_half_day': true,
      'min_notice_days': 3,
      'max_past_days': 0,
      'max_consecutive_days': null,
      'requires_reason': false,
    },
  },
  {
    'id': 2,
    'code': 'SICK',
    'name': 'Sick Leave',
    'description': 'Illness or medical treatment.',
    'is_paid': true,
    'color': 'orange',
    'policy': {
      'annual_allowance': null,
      'unlimited': true,
      'allow_negative': false,
      'allow_half_day': true,
      'min_notice_days': 0,
      'max_past_days': 7,
      'max_consecutive_days': null,
      'requires_reason': true,
    },
  },
  {
    'id': 3,
    'code': 'PERSONAL',
    'name': 'Personal Leave',
    'description': 'Short personal or family matters.',
    'is_paid': true,
    'color': 'purple',
    'policy': {
      'annual_allowance': '3.0',
      'unlimited': false,
      'allow_negative': false,
      'allow_half_day': true,
      'min_notice_days': 1,
      'max_past_days': 0,
      'max_consecutive_days': 2,
      'requires_reason': true,
    },
  },
  {
    'id': 4,
    'code': 'UNPAID',
    'name': 'Unpaid Leave',
    'description': 'Time off without pay, deducted in payroll.',
    'is_paid': false,
    'color': 'gray',
    'policy': {
      'annual_allowance': null,
      'unlimited': true,
      'allow_negative': false,
      'allow_half_day': true,
      'min_notice_days': 3,
      'max_past_days': 0,
      'max_consecutive_days': null,
      'requires_reason': true,
    },
  },
  {
    'id': 5,
    'code': 'OTHER',
    'name': 'Other Leave',
    'description':
        'Bereavement, marriage and other special leave approved by HR.',
    'is_paid': true,
    'color': 'teal',
    'policy': {
      'annual_allowance': null,
      'unlimited': true,
      'allow_negative': false,
      'allow_half_day': true,
      'min_notice_days': 0,
      'max_past_days': 3,
      'max_consecutive_days': null,
      'requires_reason': true,
    },
  },
];

/// Fixed public holidays used by the demo calendar (month, day).
const _holidays = [(1, 1), (5, 1), (8, 17), (12, 25)];

bool isDemoHoliday(DateTime d) =>
    _holidays.any((h) => h.$1 == d.month && h.$2 == d.day);

bool isDemoWorkingDay(DateTime d) =>
    d.weekday <= DateTime.friday && !isDemoHoliday(d);

/// Working dates between [start] and [end] (inclusive): weekends and
/// holidays are free, exactly like the server's schedule rules.
List<DateTime> demoWorkingDates(DateTime start, DateTime end) {
  final out = <DateTime>[];
  for (
    var d = start;
    !d.isAfter(end);
    d = DateTime(d.year, d.month, d.day + 1)
  ) {
    if (isDemoWorkingDay(d)) out.add(d);
  }
  return out;
}

String _fmt(double v) => v.toStringAsFixed(1);
double _num(Object? v) => v == null ? 0 : double.parse(v.toString());

Map<String, dynamic> _typeRef(Map<String, dynamic> t) => {
  'id': t['id'],
  'code': t['code'],
  'name': t['name'],
  'color': t['color'],
  'is_paid': t['is_paid'],
};

Map<String, dynamic> demoLeaveTypeById(int id) => demoLeaveTypes.firstWhere(
  (t) => t['id'] == id,
  orElse: () => throw const ValidationException(
    'This leave type is not available.',
    code: 'LEAVE_TYPE_UNAVAILABLE',
  ),
);

/// Leave rules of the demo backend. Works on the JSON request maps kept in
/// the demo state, so responses have exactly the API's shapes.
class DemoLeaveBook {
  DemoLeaveBook({
    required this.requests,
    required this.today,
    required this.now,
    required this.nextId,
  });

  final List<Map<String, dynamic>> requests;
  final DateTime today;
  final ZonedTime now;
  final int Function() nextId;

  static bool _live(Map<String, dynamic> r) =>
      r['status'] == 'PENDING' || r['status'] == 'APPROVED';

  Map<String, dynamic> balance(int typeId, int year) {
    final type = demoLeaveTypeById(typeId);
    final policy = type['policy'] as Map<String, dynamic>;
    final unlimited = policy['unlimited'] == true;
    double sum(String status) => requests
        .where(
          (r) =>
              r['leave_type']['id'] == typeId &&
              r['status'] == status &&
              parseDateOnly(r['start_date'])!.year == year,
        )
        .fold(0.0, (s, r) => s + _num(r['days']));
    final used = sum('APPROVED');
    final reserved = sum('PENDING');
    final entitlement = unlimited ? null : _num(policy['annual_allowance']);
    return {
      'id': typeId * 100 + year % 100,
      'employee_id': 12,
      'leave_type': _typeRef(type),
      'year': year,
      'unlimited': unlimited,
      'allowance': entitlement == null ? null : _fmt(entitlement),
      'carried_over': '0.0',
      'adjustment': '0.0',
      'entitlement': entitlement == null ? null : _fmt(entitlement),
      'used': _fmt(used),
      'reserved': _fmt(reserved),
      'available': entitlement == null
          ? null
          : _fmt(entitlement - used - reserved),
    };
  }

  List<Map<String, dynamic>> balances(int year) => [
    for (final t in demoLeaveTypes) balance(t['id'] as int, year),
  ];

  /// Validates a draft. [forSubmit] adds the submit-only rules (notice,
  /// reason); [checkBookings] adds overlap and balance (skipped by preview,
  /// like the API). Returns the working dates.
  List<DateTime> validate(
    Map<String, dynamic> body, {
    required bool forSubmit,
    required bool checkBookings,
    int? ignoreId,
  }) {
    final errors = <String, List<String>>{};
    final typeId = body['leave_type_id'];
    final start = parseDateOnly(body['start_date']);
    final end = parseDateOnly(body['end_date']);
    if (typeId == null) errors['leave_type_id'] = ['Choose a leave type.'];
    if (start == null) errors['start_date'] = ['Choose a start date.'];
    if (end == null) errors['end_date'] = ['Choose an end date.'];
    if (errors.isNotEmpty) {
      throw ValidationException(
        'The given data was invalid.',
        code: 'VALIDATION_ERROR',
        fieldErrors: errors,
      );
    }
    final type = demoLeaveTypeById(typeId as int);
    final policy = type['policy'] as Map<String, dynamic>;
    final halfDay = body['half_day'] as String?;

    if (end!.isBefore(start!)) {
      throw const ValidationException(
        'Invalid range.',
        code: 'INVALID_DATE_RANGE',
      );
    }
    if (halfDay != null && start != end) {
      throw const ValidationException(
        'A half-day request must start and end on the same date.',
        code: 'HALF_DAY_SINGLE_DATE',
      );
    }
    if (halfDay != null && policy['allow_half_day'] != true) {
      throw const ValidationException(
        'Half days are not allowed.',
        code: 'HALF_DAY_NOT_ALLOWED',
      );
    }
    if (start.year != end.year) {
      throw const ValidationException(
        'A request cannot span two leave years.',
        code: 'LEAVE_SPANS_YEARS',
      );
    }
    final maxPast = policy['max_past_days'] as int;
    if (start.isBefore(today.subtract(Duration(days: maxPast)))) {
      throw ValidationException(
        '${type['name']} can start at most $maxPast day(s) in the past.',
        code: 'LEAVE_TOO_FAR_IN_PAST',
      );
    }
    final notice = policy['min_notice_days'] as int;
    if (forSubmit &&
        notice > 0 &&
        start.isBefore(today.add(Duration(days: notice)))) {
      throw ValidationException(
        '${type['name']} needs $notice day(s) notice.',
        code: 'LEAVE_NOTICE_TOO_SHORT',
      );
    }
    final dates = demoWorkingDates(start, end);
    if (dates.isEmpty) {
      throw const ValidationException(
        'The selected dates contain no working days.',
        code: 'LEAVE_NO_WORKING_DAYS',
      );
    }
    final maxDays = policy['max_consecutive_days'] as int?;
    if (maxDays != null && dates.length > maxDays) {
      throw ValidationException(
        '${type['name']} allows at most $maxDays working day(s) per request.',
        code: 'LEAVE_TOO_LONG',
      );
    }
    final reason = (body['reason'] as String?)?.trim() ?? '';
    if (forSubmit && policy['requires_reason'] == true && reason.isEmpty) {
      throw const ValidationException(
        'A reason is required for this leave type.',
        code: 'VALIDATION_ERROR',
        fieldErrors: {
          'reason': ['A reason is required for this leave type.'],
        },
      );
    }
    if (checkBookings) {
      _checkOverlap(start, end, halfDay, ignoreId);
      _checkBalance(typeId, start.year, days(dates, halfDay), policy);
    }
    return dates;
  }

  static double days(List<DateTime> dates, String? halfDay) =>
      halfDay == null ? dates.length.toDouble() : 0.5;

  void _checkOverlap(
    DateTime start,
    DateTime end,
    String? halfDay,
    int? ignoreId,
  ) {
    for (final r in requests.where(_live)) {
      if (r['id'] == ignoreId) continue;
      final s = parseDateOnly(r['start_date'])!;
      final e = parseDateOnly(r['end_date'])!;
      if (start.isAfter(e) || end.isBefore(s)) continue;
      // Opposite halves of the same single day do not collide.
      final other = r['half_day'] as String?;
      if (halfDay != null && other != null && halfDay != other) continue;
      throw ConflictException(
        'These dates overlap leave request #${r['id']}.',
        code: 'LEAVE_OVERLAP',
      );
    }
  }

  void _checkBalance(
    int typeId,
    int year,
    double days,
    Map<String, dynamic> policy,
  ) {
    if (policy['unlimited'] == true || policy['allow_negative'] == true) return;
    final available = _num(balance(typeId, year)['available']);
    if (days > available) {
      throw ValidationException(
        'Not enough balance.',
        code: 'LEAVE_INSUFFICIENT_BALANCE',
        details: {'available': _fmt(available), 'requested': _fmt(days)},
      );
    }
  }

  Map<String, dynamic> preview(Map<String, dynamic> body) {
    final dates = validate(body, forSubmit: true, checkBookings: false);
    final typeId = body['leave_type_id'] as int;
    final start = parseDateOnly(body['start_date'])!;
    final d = days(dates, body['half_day'] as String?);
    final bal = balance(typeId, start.year);
    return {
      'days': _fmt(d),
      'working_dates': dates.map(formatDateOnly).toList(),
      'balance': bal,
      'available_after': bal['available'] == null
          ? null
          : _fmt(_num(bal['available']) - d),
    };
  }

  Map<String, dynamic> create(
    Map<String, dynamic> body, {
    required bool submit,
  }) {
    final dates = validate(body, forSubmit: submit, checkBookings: submit);
    final type = demoLeaveTypeById(body['leave_type_id'] as int);
    final r = <String, dynamic>{
      'id': nextId(),
      'employee': {
        'id': 12,
        'full_name': 'Michael Carter',
        'employee_number': 'EMP-0012',
      },
      'leave_type': _typeRef(type),
      'start_date': body['start_date'],
      'end_date': body['end_date'],
      'half_day': body['half_day'],
      'days': _fmt(days(dates, body['half_day'] as String?)),
      'reason': (body['reason'] as String?)?.trim(),
      'status': submit ? 'PENDING' : 'DRAFT',
      'submitted_at': submit ? now.toIso() : null,
      'reviewed_at': null,
      'reviewer': null,
      'review_note': null,
      'cancelled_at': null,
      'cancel_reason': null,
      'created_at': now.toIso(),
      'working_dates': dates.map(formatDateOnly).toList(),
    };
    requests.add(r);
    return r;
  }

  Map<String, dynamic> find(int id) => requests.firstWhere(
    (r) => r['id'] == id,
    orElse: () => throw const NotFoundException('Leave request not found.'),
  );

  Map<String, dynamic> update(int id, Map<String, dynamic> body) {
    final r = find(id);
    if (r['status'] != 'DRAFT') {
      throw const ConflictException(
        'Only drafts can be edited.',
        code: 'REQUEST_NOT_PENDING',
      );
    }
    final dates = validate(body, forSubmit: false, checkBookings: false);
    final type = demoLeaveTypeById(body['leave_type_id'] as int);
    r
      ..['leave_type'] = _typeRef(type)
      ..['start_date'] = body['start_date']
      ..['end_date'] = body['end_date']
      ..['half_day'] = body['half_day']
      ..['days'] = _fmt(days(dates, body['half_day'] as String?))
      ..['reason'] = (body['reason'] as String?)?.trim()
      ..['working_dates'] = dates.map(formatDateOnly).toList();
    return r;
  }

  Map<String, dynamic> submit(int id) {
    final r = find(id);
    if (r['status'] != 'DRAFT') {
      throw const ConflictException(
        'Only drafts can be submitted.',
        code: 'REQUEST_NOT_PENDING',
      );
    }
    validate(
      {
        'leave_type_id': r['leave_type']['id'],
        'start_date': r['start_date'],
        'end_date': r['end_date'],
        'half_day': r['half_day'],
        'reason': r['reason'],
      },
      forSubmit: true,
      checkBookings: true,
      ignoreId: id,
    );
    r
      ..['status'] = 'PENDING'
      ..['submitted_at'] = now.toIso();
    return r;
  }

  Map<String, dynamic> cancel(int id, String? reason) {
    final r = find(id);
    final status = r['status'];
    if (status == 'APPROVED') {
      final start = parseDateOnly(r['start_date'])!;
      if (!start.isAfter(today)) {
        throw const ConflictException(
          'This leave has already started.',
          code: 'LEAVE_ALREADY_STARTED',
        );
      }
    } else if (status != 'PENDING' && status != 'DRAFT') {
      throw const ConflictException(
        'This request can no longer be cancelled.',
        code: 'REQUEST_NOT_PENDING',
      );
    }
    r
      ..['status'] = 'CANCELLED'
      ..['cancelled_at'] = now.toIso()
      ..['cancel_reason'] = reason;
    return r;
  }

  /// Approved full-day leave covering [day], if any.
  Map<String, dynamic>? approvedOn(DateTime day) {
    for (final r in requests) {
      if (r['status'] != 'APPROVED' || r['half_day'] != null) continue;
      final s = parseDateOnly(r['start_date'])!;
      final e = parseDateOnly(r['end_date'])!;
      if (!day.isBefore(s) && !day.isAfter(e)) return r;
    }
    return null;
  }
}
