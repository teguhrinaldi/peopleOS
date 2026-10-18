import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Maps the API colour token of a leave type to the PeopleOS palette.
Color leaveTypeColor(BuildContext context, String token) {
  final c = context.colors;
  return switch (token) {
    'orange' || 'amber' => c.warningText,
    'purple' || 'violet' => const Color(0xFF7C3AED),
    'gray' || 'grey' || 'slate' => c.textSecondary,
    'teal' || 'cyan' => c.infoText,
    'green' => c.successText,
    'red' => c.errorText,
    _ => c.primaryText,
  };
}

StatusBadge leaveStatusBadge(
  LeaveStatus s,
  AppLocalizations l10n, {
  bool dense = false,
}) {
  final tone = switch (s) {
    LeaveStatus.draft => BadgeTone.neutral,
    LeaveStatus.pending => BadgeTone.warning,
    LeaveStatus.approved => BadgeTone.success,
    LeaveStatus.rejected => BadgeTone.error,
    LeaveStatus.cancelled => BadgeTone.neutral,
  };
  return StatusBadge(label: s.label(l10n), tone: tone, dense: dense);
}

/// "26 Oct", "26 – 28 Oct 2026", "30 Dec 2026 – 2 Jan 2027" (en);
/// "26 – 28 Okt 2026" (id). [locale] is the intl tag (`context.fmt.tag`).
String formatLeaveRange(LeaveRequest r, String locale) =>
    formatDateRange(r.startDate, r.endDate, locale);

String formatDateRange(DateTime start, DateTime end, String locale) {
  final dayMonth = DateFormat('d MMM', locale);
  final dayMonthYear = DateFormat('d MMM yyyy', locale);
  if (start == end) return dayMonthYear.format(start);
  if (start.year == end.year && start.month == end.month) {
    return '${start.day} – ${dayMonthYear.format(end)}';
  }
  if (start.year == end.year) {
    return '${dayMonth.format(start)} – ${dayMonthYear.format(end)}';
  }
  return '${dayMonthYear.format(start)} – ${dayMonthYear.format(end)}';
}

String leaveDuration(LeaveRequest r, AppLocalizations l10n) {
  // The API does not count days for drafts yet (it returns 0).
  if (r.status == LeaveStatus.draft && r.days == 0) {
    return l10n.leaveDurationCountedOnSubmit;
  }
  final days = formatDays(l10n, r.days);
  final half = r.halfDay;
  return half == null
      ? days
      : l10n.leaveDurationWithHalf(days, half.label(l10n));
}

/// Horizontal strip of balance cards, one per leave type.
class LeaveBalanceStrip extends StatelessWidget {
  const LeaveBalanceStrip({super.key, required this.balances});

  final List<LeaveBalance> balances;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 118 + (MediaQuery.textScalerOf(context).scale(1) - 1) * 80,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.page),
        itemCount: balances.length,
        separatorBuilder: (_, _) => const SizedBox(width: PeopleSpacing.sm),
        itemBuilder: (context, i) => _BalanceCard(balance: balances[i]),
      ),
    );
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.balance});
  final LeaveBalance balance;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final fmt = context.fmt;
    String n(double v) =>
        fmt.number(v, decimals: v == v.roundToDouble() ? 0 : 1);
    final b = balance;
    final tint = leaveTypeColor(context, b.type.color);
    final headline = b.unlimited ? n(b.used) : n(b.available ?? 0);
    final caption = b.unlimited
        ? l10n.leaveBalanceDaysUsedYear
        : l10n.leaveBalanceOfAvailable(n(b.entitlement ?? 0));
    final footer = b.unlimited
        ? (b.reserved > 0
              ? l10n.leaveBalancePending(n(b.reserved))
              : l10n.leaveBalanceNoLimit)
        : l10n.leaveBalanceUsedPending(n(b.used), n(b.reserved));
    final low = !b.unlimited && (b.available ?? 0) <= 0;
    return Semantics(
      label: l10n.leaveBalanceSemantics(b.type.name, headline, caption, footer),
      excludeSemantics: true,
      child: SizedBox(
        width: 168,
        child: AppCard(
          shadow: false,
          padding: const EdgeInsets.all(PeopleSpacing.sm + 2),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    width: 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: tint,
                      shape: BoxShape.circle,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      b.type.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: t.labelLarge!.copyWith(fontSize: 13),
                    ),
                  ),
                ],
              ),
              const Spacer(),
              Text(
                headline,
                style: t.headlineMedium!.copyWith(
                  color: low ? c.errorText : c.textPrimary,
                  height: 1.1,
                ),
              ),
              Text(
                caption,
                style: t.labelSmall,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 2),
              Text(
                footer,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: t.labelSmall!.copyWith(color: c.textMuted),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class LeaveRequestCard extends StatelessWidget {
  const LeaveRequestCard({super.key, required this.request, this.onTap});

  final LeaveRequest request;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final r = request;
    final tint = leaveTypeColor(context, r.type.color);
    final l10n = context.l10n;
    final range = formatLeaveRange(r, context.fmt.tag);
    final duration = leaveDuration(r, l10n);
    return AppCard(
      onTap: onTap,
      shadow: false,
      semanticLabel: l10n.leaveCardSemantics(
        r.type.name,
        range,
        duration,
        r.status.label(l10n),
      ),
      padding: const EdgeInsets.all(PeopleSpacing.md),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(icon: Icons.beach_access_outlined, color: tint, size: 40),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(range, style: t.titleSmall),
                const SizedBox(height: 2),
                Text(
                  l10n.leaveCardSubtitle(r.type.name, duration),
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
                if (r.reason != null && r.reason!.isNotEmpty)
                  Text(
                    r.reason!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: t.bodySmall!.copyWith(color: c.textMuted),
                  ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          leaveStatusBadge(r.status, l10n),
        ],
      ),
    );
  }
}
