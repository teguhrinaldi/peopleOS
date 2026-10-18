import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/features/attendance/domain/effective_today.dart';
import 'package:peopleos/l10n/l10n.dart';

/// "Today's Shift": shift name, time range and work location, tinted green
/// on a normal working day as in the reference.
class ShiftCard extends StatelessWidget {
  const ShiftCard({super.key, required this.today});

  final EffectiveToday today;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final l10n = context.l10n;
    final t = Theme.of(context).textTheme;
    final s = today.schedule;
    final working =
        s != null &&
        s.isWorkingDay &&
        today.state != TodayState.blocked &&
        today.state != TodayState.onLeave;
    final tone = working ? c.success : c.textMuted;
    final soft = working ? c.successSoft : c.surfaceMuted;

    final String headline;
    final String trailing;
    if (working) {
      headline = s.timeRange;
      trailing = today.state == TodayState.notCheckedIn
          ? l10n.homeNotStarted
          : l10n.homeOnSchedule;
    } else if (s == null) {
      headline = l10n.homeNoShiftAssigned;
      trailing = '';
    } else if (s.isWorkingDay) {
      headline = s.timeRange;
      trailing = today.state == TodayState.onLeave ? l10n.homeBadgeOnLeave : '';
    } else {
      headline = today.server.holidayName ?? l10n.homeRestDay;
      trailing = '';
    }

    final detail = [
      if (working && s.shiftName != null) s.shiftName!,
      if (s?.location != null) s!.location!.name,
    ].join(' · ');

    return AppCard(
      color: soft,
      borderColor: working ? c.success.withValues(alpha: 0.25) : c.border,
      shadow: false,
      padding: const EdgeInsets.all(PeopleSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Semantics(
                  header: true,
                  child: Text(l10n.homeTodaysShift, style: t.titleSmall),
                ),
              ),
              Text(
                context.fmt.weekdayShort(today.server.today),
                style: t.bodySmall!.copyWith(color: c.textSecondary),
              ),
            ],
          ),
          const SizedBox(height: PeopleSpacing.sm),
          Row(
            children: [
              IconTile(
                icon: working ? Icons.schedule_rounded : Icons.event_outlined,
                color: working ? c.successText : c.textSecondary,
                background: working
                    ? c.success.withValues(alpha: 0.16)
                    : c.neutralSoft,
                size: 36,
                iconSize: 20,
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Expanded(
                child: Text(
                  headline,
                  style: t.titleMedium!.copyWith(fontWeight: FontWeight.w600),
                ),
              ),
              if (trailing.isNotEmpty)
                Text(
                  trailing,
                  style: t.labelLarge!.copyWith(
                    color: working ? c.successText : tone,
                  ),
                ),
            ],
          ),
          if (detail.isNotEmpty) ...[
            const SizedBox(height: PeopleSpacing.xs),
            Padding(
              padding: const EdgeInsets.only(left: 36 + PeopleSpacing.sm),
              child: Text(
                detail,
                style: t.bodySmall!.copyWith(color: c.textSecondary),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
