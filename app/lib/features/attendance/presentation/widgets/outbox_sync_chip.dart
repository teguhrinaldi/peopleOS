import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/widgets/connectivity_widgets.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Sync chip bound to the outbox. Hidden when there is nothing to report
/// unless [alwaysShow] is set (then it reads "All synced").
class OutboxSyncChip extends ConsumerWidget {
  const OutboxSyncChip({super.key, this.alwaysShow = false});

  final bool alwaysShow;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(outboxControllerProvider);
    final failed = s.failed.length;
    final pending = s.pendingCount;
    final l10n = context.l10n;

    if (s.syncing) {
      return SyncStatusChip(
        visual: SyncVisual.syncing,
        label: l10n.syncSyncing,
        onTap: () => context.push(Routes.sync),
      );
    }
    if (failed > 0) {
      return SyncStatusChip(
        visual: SyncVisual.failed,
        label: l10n.syncFailedCount(failed),
        onTap: () => context.push(Routes.sync),
      );
    }
    if (pending > 0) {
      return SyncStatusChip(
        visual: SyncVisual.pending,
        label: l10n.syncPendingCount(pending),
        onTap: () => context.push(Routes.sync),
      );
    }
    if (!alwaysShow) return const SizedBox.shrink();
    return SyncStatusChip(
      visual: SyncVisual.synced,
      label: l10n.syncAllSynced,
      onTap: () => context.push(Routes.sync),
    );
  }
}
