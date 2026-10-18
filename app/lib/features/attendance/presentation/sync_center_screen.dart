import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/zoned_time.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/attendance/domain/outbox_item.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Every attendance action recorded on this device and where it stands.
class SyncCenterScreen extends ConsumerWidget {
  const SyncCenterScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(outboxControllerProvider);
    final online = ref.watch(isOnlineProvider);
    final items = [...state.items]
      ..sort((a, b) => b.deviceCapturedAt.compareTo(a.deviceCapturedAt));
    final t = Theme.of(context).textTheme;
    final c = context.colors;
    final l10n = context.l10n;

    return SubPage(
      title: l10n.syncTitle,
      bottom: PrimaryButton(
        label: l10n.syncNow,
        icon: Icons.sync_rounded,
        loading: state.syncing,
        onPressed: online && state.hasWork
            ? () => unawaited(
                ref.read(outboxControllerProvider.notifier).syncNow(),
              )
            : null,
      ),
      child: items.isEmpty
          ? EmptyState(
              icon: Icons.cloud_done_outlined,
              title: l10n.syncEverythingSynced,
              message: l10n.syncEverythingSyncedMsg,
            )
          : ListView(
              padding: const EdgeInsets.all(PeopleSpacing.page),
              children: [
                Row(
                  children: [
                    Icon(
                      online ? Icons.wifi_rounded : Icons.wifi_off_rounded,
                      size: 18,
                      color: online ? c.successText : c.warningText,
                    ),
                    const SizedBox(width: PeopleSpacing.xs),
                    Text(
                      online ? l10n.syncOnline : l10n.syncOffline,
                      style: t.labelLarge,
                    ),
                    const Spacer(),
                    Text(
                      l10n.syncPendingFailedSummary(
                        state.pendingCount,
                        state.failed.length,
                      ),
                      style: t.bodySmall,
                    ),
                  ],
                ),
                const SizedBox(height: PeopleSpacing.md),
                for (final item in items) ...[
                  _ItemCard(item: item),
                  const SizedBox(height: PeopleSpacing.sm),
                ],
              ],
            ),
    );
  }
}

class _ItemCard extends ConsumerWidget {
  const _ItemCard({required this.item});
  final OutboxItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final at = ZonedTime(
      item.deviceCapturedAt,
      Duration(minutes: item.utcOffsetMinutes),
    );
    final l10n = context.l10n;
    final (String label, BadgeTone tone) = switch (item.syncState) {
      SyncState.local => (l10n.syncStateSaved, BadgeTone.neutral),
      SyncState.pendingSync => (l10n.attendancePendingSync, BadgeTone.warning),
      SyncState.synced => (l10n.syncStateSynced, BadgeTone.success),
      SyncState.syncFailed => (l10n.attendanceSyncFailed, BadgeTone.error),
    };
    final controller = ref.read(outboxControllerProvider.notifier);
    final errorText = item.errorText(l10n);

    return AppCard(
      shadow: false,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  '${item.type.label(l10n)} · ${at.hhmm}',
                  style: t.titleSmall,
                ),
              ),
              StatusBadge(label: label, tone: tone, dense: true),
            ],
          ),
          const SizedBox(height: 2),
          Text(
            [
              context.fmt.shortDate(at.wall),
              ?item.siteName,
              if (item.attempts > 0) l10n.syncAttempts(item.attempts),
            ].join(' · '),
            style: t.bodySmall!.copyWith(color: c.textMuted),
          ),
          if (errorText != null && item.syncState != SyncState.synced) ...[
            const SizedBox(height: PeopleSpacing.xs),
            Text(
              errorText,
              style: t.bodySmall!.copyWith(
                color: item.syncState == SyncState.syncFailed
                    ? c.errorText
                    : c.warningText,
              ),
            ),
          ],
          if (item.syncState == SyncState.syncFailed) ...[
            const SizedBox(height: PeopleSpacing.sm),
            Row(
              children: [
                Expanded(
                  child: OutlineButton(
                    label: l10n.commonRetry,
                    icon: Icons.refresh_rounded,
                    onPressed: () =>
                        unawaited(controller.retry(item.idempotencyKey)),
                  ),
                ),
                const SizedBox(width: PeopleSpacing.xs),
                Expanded(
                  child: DangerButton(
                    label: l10n.syncDiscard,
                    icon: Icons.delete_outline_rounded,
                    onPressed: () async {
                      final ok = await confirmDialog(
                        context,
                        title: l10n.syncDiscardTitle,
                        message: l10n.syncDiscardMessage,
                        confirmLabel: l10n.syncDiscard,
                        destructive: true,
                      );
                      if (ok) await controller.discard(item.idempotencyKey);
                    },
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
