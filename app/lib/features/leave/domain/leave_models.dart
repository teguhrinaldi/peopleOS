import 'package:flutter/foundation.dart';
import 'package:peopleos/core/utils/json_reader.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Days and balances travel as one-decimal strings ("2.5").
double? _days(JsonReader j, String key) {
  final v = j.map[key];
  if (v == null) return null;
  if (v is num) return v.toDouble();
  return double.tryParse(v.toString());
}

/// "2.5 days" (en) / "2,5 hari" (id), in the language of [l10n].
String formatDays(AppLocalizations l10n, double days) =>
    daysL10n(l10n, days, l10n.localeName);

enum LeaveStatus {
  draft('DRAFT'),
  pending('PENDING'),
  approved('APPROVED'),
  rejected('REJECTED'),
  cancelled('CANCELLED');

  const LeaveStatus(this.wire);
  final String wire;

  String label(AppLocalizations l10n) => switch (this) {
    draft => l10n.leaveStatusDraft,
    pending => l10n.leaveStatusPending,
    approved => l10n.leaveStatusApproved,
    rejected => l10n.leaveStatusRejected,
    cancelled => l10n.leaveStatusCancelled,
  };

  static LeaveStatus parse(String? raw) => LeaveStatus.values.firstWhere(
    (s) => s.wire == raw,
    orElse: () => LeaveStatus.pending,
  );
}

enum HalfDay {
  am('AM'),
  pm('PM');

  const HalfDay(this.wire);
  final String wire;

  String label(AppLocalizations l10n) => switch (this) {
    am => l10n.leaveHalfDayAm,
    pm => l10n.leaveHalfDayPm,
  };

  static HalfDay? parse(String? raw) => switch (raw) {
    'AM' => am,
    'PM' => pm,
    _ => null,
  };
}

@immutable
class LeavePolicy {
  const LeavePolicy({
    this.unlimited = false,
    this.annualAllowance,
    this.allowHalfDay = false,
    this.allowNegative = false,
    this.minNoticeDays = 0,
    this.maxPastDays = 0,
    this.maxConsecutiveDays,
    this.requiresReason = false,
  });

  final bool unlimited;
  final double? annualAllowance;
  final bool allowHalfDay;
  final bool allowNegative;
  final int minNoticeDays;
  final int maxPastDays;
  final int? maxConsecutiveDays;
  final bool requiresReason;

  factory LeavePolicy.fromJson(JsonReader j) => LeavePolicy(
    unlimited: j.boolOr('unlimited', false),
    annualAllowance: _days(j, 'annual_allowance'),
    allowHalfDay: j.boolOr('allow_half_day', false),
    allowNegative: j.boolOr('allow_negative', false),
    minNoticeDays: j.intOr('min_notice_days', 0),
    maxPastDays: j.intOr('max_past_days', 0),
    maxConsecutiveDays: j.intOrNull('max_consecutive_days'),
    requiresReason: j.boolOr('requires_reason', false),
  );

  /// Short human hints shown under the type picker.
  List<String> hints(AppLocalizations l10n) => [
    if (unlimited)
      l10n.leaveHintUnlimited
    else if (annualAllowance != null)
      l10n.leaveHintPerYear(formatDays(l10n, annualAllowance!)),
    if (minNoticeDays > 0) l10n.leaveHintNotice(minNoticeDays),
    if (maxPastDays > 0) l10n.leaveHintMaxPast(maxPastDays),
    if (maxConsecutiveDays != null)
      l10n.leaveHintMaxConsecutive(maxConsecutiveDays!),
    if (allowHalfDay) l10n.leaveHintHalfDay,
    if (requiresReason) l10n.leaveHintReasonRequired,
  ];
}

@immutable
class LeaveTypeRef {
  const LeaveTypeRef({
    required this.id,
    required this.code,
    required this.name,
    this.color = 'blue',
    this.isPaid = true,
  });

  final int id;
  final String code;
  final String name;
  final String color;
  final bool isPaid;

  factory LeaveTypeRef.fromJson(JsonReader j) => LeaveTypeRef(
    id: j.intOrNull('id') ?? 0,
    code: j.str('code') ?? '',
    name: j.str('name') ?? 'Leave',
    color: j.str('color') ?? 'blue',
    isPaid: j.boolOr('is_paid', true),
  );
}

@immutable
class LeaveType {
  const LeaveType({required this.ref, this.description, this.policy});

  final LeaveTypeRef ref;
  final String? description;

  /// Policy that applies to the signed-in employee; null = not configured.
  final LeavePolicy? policy;

  int get id => ref.id;
  String get name => ref.name;

  factory LeaveType.fromJson(JsonReader j) {
    final p = j.obj('policy');
    return LeaveType(
      ref: LeaveTypeRef.fromJson(j),
      description: j.str('description'),
      policy: p == null ? null : LeavePolicy.fromJson(p),
    );
  }
}

@immutable
class LeaveBalance {
  const LeaveBalance({
    required this.type,
    required this.year,
    required this.unlimited,
    required this.used,
    required this.reserved,
    this.entitlement,
    this.available,
  });

  final LeaveTypeRef type;
  final int year;
  final bool unlimited;
  final double? entitlement;
  final double used;
  final double reserved;

  /// null when [unlimited].
  final double? available;

  factory LeaveBalance.fromJson(JsonReader j) => LeaveBalance(
    type: LeaveTypeRef.fromJson(j.obj('leave_type') ?? const JsonReader({})),
    year: j.intOr('year', DateTime.now().year),
    unlimited: j.boolOr('unlimited', false),
    entitlement: _days(j, 'entitlement'),
    used: _days(j, 'used') ?? 0,
    reserved: _days(j, 'reserved') ?? 0,
    available: _days(j, 'available'),
  );
}

@immutable
class LeaveRequest {
  const LeaveRequest({
    required this.id,
    required this.type,
    required this.startDate,
    required this.endDate,
    required this.days,
    required this.status,
    this.halfDay,
    this.reason,
    this.submittedAt,
    this.reviewedAt,
    this.reviewerName,
    this.reviewNote,
    this.cancelledAt,
    this.cancelReason,
    this.createdAt,
    this.workingDates = const [],
  });

  final int id;
  final LeaveTypeRef type;
  final DateTime startDate;
  final DateTime endDate;
  final HalfDay? halfDay;
  final double days;
  final String? reason;
  final LeaveStatus status;
  final ZonedTime? submittedAt;
  final ZonedTime? reviewedAt;
  final String? reviewerName;
  final String? reviewNote;
  final ZonedTime? cancelledAt;
  final String? cancelReason;
  final ZonedTime? createdAt;
  final List<DateTime> workingDates;

  bool get isSingleDay => startDate == endDate;

  /// Approved leave can still be cancelled before it starts.
  bool canCancel(DateTime today) =>
      status == LeaveStatus.pending ||
      status == LeaveStatus.draft ||
      (status == LeaveStatus.approved && startDate.isAfter(today));

  factory LeaveRequest.fromJson(JsonReader j) {
    final reviewer = j.obj('reviewer');
    final dates = j.map['working_dates'];
    return LeaveRequest(
      id: j.intOrNull('id') ?? 0,
      type: LeaveTypeRef.fromJson(j.obj('leave_type') ?? const JsonReader({})),
      startDate: j.date('start_date') ?? DateTime.now(),
      endDate: j.date('end_date') ?? j.date('start_date') ?? DateTime.now(),
      halfDay: HalfDay.parse(j.str('half_day')),
      days: _days(j, 'days') ?? 0,
      reason: j.str('reason'),
      status: LeaveStatus.parse(j.str('status')),
      submittedAt: j.time('submitted_at'),
      reviewedAt: j.time('reviewed_at'),
      reviewerName: reviewer?.str('name') ?? reviewer?.str('full_name'),
      reviewNote: j.str('review_note'),
      cancelledAt: j.time('cancelled_at'),
      cancelReason: j.str('cancel_reason'),
      createdAt: j.time('created_at'),
      workingDates: dates is List
          ? dates.map(parseDateOnly).whereType<DateTime>().toList()
          : const [],
    );
  }
}

/// What the user is asking for (create, update, preview).
@immutable
class LeaveDraft {
  const LeaveDraft({
    required this.typeId,
    required this.start,
    required this.end,
    this.halfDay,
    this.reason,
  });

  final int typeId;
  final DateTime start;
  final DateTime end;
  final HalfDay? halfDay;
  final String? reason;

  Map<String, dynamic> toJson() => {
    'leave_type_id': typeId,
    'start_date': formatDateOnly(start),
    'end_date': formatDateOnly(end),
    'half_day': ?halfDay?.wire,
    if (reason != null && reason!.trim().isNotEmpty) 'reason': reason!.trim(),
  };
}

/// `POST /leave-requests/preview`.
@immutable
class LeavePreview {
  const LeavePreview({
    required this.days,
    required this.workingDates,
    this.balance,
    this.availableAfter,
  });

  final double days;
  final List<DateTime> workingDates;
  final LeaveBalance? balance;

  /// null for unlimited types; may be negative (not enough balance).
  final double? availableAfter;

  bool get exceedsBalance => availableAfter != null && availableAfter! < 0;

  factory LeavePreview.fromJson(JsonReader j) {
    final dates = j.map['working_dates'];
    final b = j.obj('balance');
    return LeavePreview(
      days: _days(j, 'days') ?? 0,
      workingDates: dates is List
          ? dates.map(parseDateOnly).whereType<DateTime>().toList()
          : const [],
      balance: b == null ? null : LeaveBalance.fromJson(b),
      availableAfter: _days(j, 'available_after'),
    );
  }
}
