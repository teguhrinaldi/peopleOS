import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/leave/domain/leave_models.dart';
import 'package:peopleos/features/leave/presentation/leave_providers.dart';
import 'package:peopleos/features/leave/presentation/widgets/leave_widgets.dart';
import 'package:peopleos/features/requests/domain/request_models.dart';
import 'package:peopleos/features/requests/presentation/requests_providers.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Compact Home card: next approved leave + number of pending requests.
/// Hidden while loading or on error so it never crowds Home.
class RequestsSummaryCard extends ConsumerWidget {
  const RequestsSummaryCard({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final leave = ref.watch(leaveRequestsProvider).value;
    if (leave == null) return const SizedBox.shrink();
    final corrections = ref.watch(correctionsProvider).value ?? const [];
    final overtime = ref.watch(overtimeProvider).value ?? const [];

    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    final upcoming =
        leave
            .where(
              (r) =>
                  r.status == LeaveStatus.approved &&
                  !r.endDate.isBefore(today),
            )
            .toList()
          ..sort((a, b) => a.startDate.compareTo(b.startDate));
    final pending =
        leave.where((r) => r.status == LeaveStatus.pending).length +
        corrections.where((r) => r.status == RequestStatus.pending).length +
        overtime.where((r) => r.status == RequestStatus.pending).length;

    final c = context.colors;
    final l10n = context.l10n;
    final t = Theme.of(context).textTheme;
    final next = upcoming.firstOrNull;
    final title = next == null ? l10n.homeNoUpcomingLeave : l10n.homeNextLeave;
    final subtitle = next == null
        ? l10n.homePlanTimeOff
        : '${next.type.name} · ${formatLeaveRange(next, context.fmt.tag)}';

    return AppCard(
      onTap: onTap,
      semanticLabel: l10n.homeSummarySemantic(title, subtitle, pending),
      padding: const EdgeInsets.symmetric(
        horizontal: PeopleSpacing.md,
        vertical: PeopleSpacing.sm + 2,
      ),
      child: Row(
        children: [
          IconTile(
            icon: Icons.beach_access_outlined,
            color: next == null
                ? c.textSecondary
                : leaveTypeColor(context, next.type.color),
            size: 40,
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: t.titleSmall),
                Text(
                  subtitle,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
              ],
            ),
          ),
          const SizedBox(width: PeopleSpacing.xs),
          if (pending > 0)
            StatusBadge(
              label: l10n.homePendingCount(pending),
              tone: BadgeTone.warning,
              icon: Icons.hourglass_top_rounded,
              dense: true,
            )
          else
            Icon(Icons.chevron_right_rounded, color: c.textMuted),
        ],
      ),
    );
  }
}
