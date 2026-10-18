import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/attendance/presentation/widgets/attendance_row.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Last few attendance entries: unsynced local actions first, then history.
class RecentActivity extends ConsumerWidget {
  const RecentActivity({
    super.key,
    required this.onViewAll,
    required this.onOpenSync,
  });

  final VoidCallback onViewAll;
  final VoidCallback onOpenSync;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.colors;
    final l10n = context.l10n;
    final now = DateTime.now();
    final month = DateTime(now.year, now.month);
    final history = ref.watch(attendanceHistoryProvider(month));
    final outbox = ref.watch(outboxControllerProvider);
    final waiting = [...outbox.active, ...outbox.failed];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SectionHeader(
          title: l10n.homeRecentActivity,
          actionLabel: l10n.homeViewAll,
          onAction: onViewAll,
        ),
        const SizedBox(height: PeopleSpacing.xs),
        for (final item in waiting) ...[
          PendingActionRow(item: item, onTap: onOpenSync),
          const SizedBox(height: PeopleSpacing.xs),
        ],
        history.when(
          skipLoadingOnRefresh: true,
          loading: () => Column(
            children: [
              for (var i = 0; i < 3; i++) ...[
                const Skeleton(height: 72, radius: PeopleRadii.card),
                const SizedBox(height: PeopleSpacing.xs),
              ],
            ],
          ),
          error: (e, _) => Padding(
            padding: const EdgeInsets.symmetric(vertical: PeopleSpacing.sm),
            child: Row(
              children: [
                Icon(Icons.cloud_off_outlined, size: 18, color: c.textMuted),
                const SizedBox(width: PeopleSpacing.xs),
                Expanded(
                  child: Text(
                    l10n.homeActivityUnavailable,
                    style: Theme.of(context).textTheme.bodySmall!
                        .copyWith(color: c.textMuted),
                  ),
                ),
                AppTextButton(
                  label: l10n.commonRetry,
                  onPressed: () =>
                      ref.invalidate(attendanceHistoryProvider(month)),
                ),
              ],
            ),
          ),
          data: (records) {
            final recent = records.take(4).toList();
            if (recent.isEmpty && waiting.isEmpty) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: PeopleSpacing.md),
                child: Text(
                  l10n.homeNoAttendanceThisMonth,
                  style: Theme.of(context).textTheme.bodyMedium!
                      .copyWith(color: c.textMuted),
                ),
              );
            }
            return Column(
              children: [
                for (final r in recent) ...[
                  AttendanceRow(record: r, onTap: onViewAll),
                  const SizedBox(height: PeopleSpacing.xs),
                ],
              ],
            );
          },
        ),
      ],
    );
  }
}
