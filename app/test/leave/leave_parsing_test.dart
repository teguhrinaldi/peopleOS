import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/l10n/generated/app_localizations_en.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/leave/domain/leave_errors.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';

import '../helpers/sample_json.dart';

void main() {
  test('leave type + policy (docs/api.md shape)', () {
    final t = LeaveType.fromJson(JsonReader(leaveTypeJson()));
    expect(t.name, 'Annual Leave');
    expect(t.policy!.annualAllowance, 12);
    expect(t.policy!.minNoticeDays, 3);
    expect(t.policy!.hints(AppLocalizationsEn()), contains('12 days per year'));
    expect(
      t.policy!.hints(AppLocalizationsEn()),
      contains('Request at least 3 days ahead'),
    );

    final sick = LeaveType.fromJson(
      JsonReader(
        leaveTypeJson(id: 2, unlimited: true, requiresReason: true, notice: 0),
      ),
    );
    expect(sick.policy!.unlimited, isTrue);
    expect(
      sick.policy!.hints(AppLocalizationsEn()).first,
      'Not limited by a yearly balance',
    );
    expect(
      sick.policy!.hints(AppLocalizationsEn()),
      contains('Reason required'),
    );
  });

  test('balance: decimal strings, unlimited has null available', () {
    final b = LeaveBalance.fromJson(JsonReader(leaveBalanceJson()));
    expect(b.entitlement, 12);
    expect(b.used, 3);
    expect(b.reserved, 3);
    expect(b.available, 6);
    final u = LeaveBalance.fromJson(
      JsonReader(leaveBalanceJson(id: 2, unlimited: true)),
    );
    expect(u.unlimited, isTrue);
    expect(u.available, isNull);
  });

  test('leave request + cancel rules', () {
    final r = LeaveRequest.fromJson(
      JsonReader(leaveRequestJson(status: 'APPROVED')),
    );
    expect(r.days, 3);
    expect(r.status, LeaveStatus.approved);
    expect(r.reviewerName, 'Amanda Clark');
    expect(r.startDate, DateTime(2026, 10, 26));
    expect(r.canCancel(DateTime(2026, 10, 20)), isTrue);
    expect(
      r.canCancel(DateTime(2026, 10, 26)),
      isFalse,
      reason: 'already started',
    );

    final half = LeaveRequest.fromJson(
      JsonReader(
        leaveRequestJson(
          start: '2026-11-05',
          end: '2026-11-05',
          halfDay: 'PM',
          days: '0.5',
        ),
      ),
    );
    expect(half.halfDay, HalfDay.pm);
    expect(formatDays(AppLocalizationsEn(), half.days), '0.5 days');
    expect(formatDays(AppLocalizationsEn(), 1), '1 day');
  });

  test('draft serialises to the create/preview body', () {
    final d = LeaveDraft(
      typeId: 1,
      start: DateTime(2026, 11, 5),
      end: DateTime(2026, 11, 5),
      halfDay: HalfDay.am,
      reason: '  Dentist  ',
    );
    expect(d.toJson(), {
      'leave_type_id': 1,
      'start_date': '2026-11-05',
      'end_date': '2026-11-05',
      'half_day': 'AM',
      'reason': 'Dentist',
    });
  });

  test('preview with negative available_after flags the balance', () {
    final p = LeavePreview.fromJson(
      JsonReader(leavePreviewJson(days: '15.0', after: '-9.0')),
    );
    expect(p.exceedsBalance, isTrue);
    expect(p.workingDates, hasLength(4));
  });

  test('notification object and navigation target', () {
    final n = AppNotification.fromJson(JsonReader(notificationJson()));
    expect(n.target, NotificationTarget.leave);
    expect(n.entityId, 7);
    expect(n.isRead, isFalse);
    expect(n.markedRead().isRead, isTrue);
    final c = AppNotification.fromJson(
      JsonReader(
        notificationJson(
          type: 'attendance_correction.rejected',
          entityType: r'App\Models\AttendanceCorrection',
        ),
      ),
    );
    expect(c.target, NotificationTarget.correction);
    expect(c.outcome, 'rejected');
    final o = AppNotification.fromJson(
      JsonReader(
        notificationJson(
          type: 'overtime.approved',
          entityType: r'App\Models\OvertimeRequest',
        ),
      ),
    );
    expect(o.target, NotificationTarget.overtime);
  });

  test('employee private fields may be null (privacy)', () {
    final json = Map<String, dynamic>.from(employeeJson())
      ..['date_of_birth'] = null
      ..['phone'] = null
      ..['address'] = null;
    final e = Employee.fromJson(JsonReader(json));
    expect(e.dateOfBirth, isNull);
    expect(e.phone, isNull);
    expect(e.address, isNull);
    expect(e.fullName, 'Michael Carter');
  });

  group('every leave error code has readable copy', () {
    const codes = [
      'LEAVE_OVERLAP',
      'LEAVE_NOTICE_TOO_SHORT',
      'LEAVE_TOO_FAR_IN_PAST',
      'LEAVE_NO_WORKING_DAYS',
      'LEAVE_SPANS_YEARS',
      'LEAVE_TOO_LONG',
      'HALF_DAY_SINGLE_DATE',
      'HALF_DAY_NOT_ALLOWED',
      'INVALID_DATE_RANGE',
      'LEAVE_TYPE_UNAVAILABLE',
      'LEAVE_POLICY_MISSING',
      'LEAVE_ALREADY_STARTED',
      'REQUEST_NOT_PENDING',
      'EMPLOYEE_TERMINATED',
      'EMPLOYEE_NOT_ACTIVE',
    ];
    for (final c in codes) {
      test(c, () {
        final text = describeLeaveError(
          ValidationException('raw $c', code: c),
          AppLocalizationsEn(),
        );
        expect(text, isNot('raw $c'));
        expect(text.length, greaterThan(20));
      });
    }

    test('LEAVE_INSUFFICIENT_BALANCE includes the numbers', () {
      final text = describeLeaveError(
        const ValidationException(
          'Not enough',
          code: 'LEAVE_INSUFFICIENT_BALANCE',
          details: {'available': '2.0', 'requested': '4.5'},
        ),
        AppLocalizationsEn(),
      );
      expect(
        text,
        'Not enough balance: you asked for 4.5 days but only 2 days are available.',
      );
    });

    test('unknown codes fall back to the server message', () {
      expect(
        describeLeaveError(
          const ValidationException('Server says no', code: 'NEW_CODE'),
          AppLocalizationsEn(),
        ),
        'Server says no',
      );
    });

    test('attendance ON_LEAVE (409) is explained', () {
      expect(
        describeAttendanceError(
          const ConflictException('x', code: 'ON_LEAVE'),
          AppLocalizationsEn(),
        ),
        contains('approved leave'),
      );
    });
  });
}
