import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Readable copy for every leave error code in docs/api.md. Falls back to the
/// server message (which already includes policy numbers) for anything new.
String describeLeaveError(AppException e, AppLocalizations l10n) {
  final details = e is ValidationException ? e.details : const {};
  double? amount(String key) {
    final v = details[key];
    if (v is num) return v.toDouble();
    return double.tryParse('${v ?? ''}');
  }

  switch (e.code) {
    case 'LEAVE_OVERLAP':
      return l10n.leaveErrOverlap;
    case 'LEAVE_INSUFFICIENT_BALANCE':
      final available = amount('available');
      final requested = amount('requested');
      if (available != null && requested != null) {
        return l10n.leaveErrInsufficientBalance(
          available,
          formatDays(l10n, requested),
          formatDays(l10n, available),
        );
      }
      return l10n.leaveErrInsufficientBalanceGeneric;
    case 'LEAVE_NOTICE_TOO_SHORT':
      return l10n.leaveErrNoticeTooShort;
    case 'LEAVE_TOO_FAR_IN_PAST':
      return l10n.leaveErrTooFarInPast;
    case 'LEAVE_NO_WORKING_DAYS':
      return l10n.leaveErrNoWorkingDays;
    case 'LEAVE_SPANS_YEARS':
      return l10n.leaveErrSpansYears;
    case 'LEAVE_TOO_LONG':
      return l10n.leaveErrTooLong;
    case 'HALF_DAY_SINGLE_DATE':
      return l10n.leaveErrHalfDaySingleDate;
    case 'HALF_DAY_NOT_ALLOWED':
      return l10n.leaveErrHalfDayNotAllowed;
    case 'INVALID_DATE_RANGE':
      return l10n.leaveErrInvalidDateRange;
    case 'LEAVE_TYPE_UNAVAILABLE':
      return l10n.leaveErrTypeUnavailable;
    case 'LEAVE_POLICY_MISSING':
      return l10n.leaveErrPolicyMissing;
    case 'LEAVE_ALREADY_STARTED':
      return l10n.leaveErrAlreadyStarted;
    case 'REQUEST_NOT_PENDING':
      return l10n.leaveErrRequestNotPending;
    case 'EMPLOYEE_TERMINATED':
      return l10n.leaveErrEmployeeTerminated;
    case 'EMPLOYEE_NOT_ACTIVE':
      return l10n.leaveErrEmployeeNotActive;
  }
  return e.message;
}
