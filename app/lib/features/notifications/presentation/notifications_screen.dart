import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/widgets/skeleton.dart';
import 'package:peopleos/core/widgets/state_views.dart';
import 'package:peopleos/features/notifications/domain/app_notification.dart';
import 'package:peopleos/features/notifications/presentation/notifications_controller.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/l10n/age.dart';
import 'package:peopleos/l10n/l10n.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  /// Marks read, then opens the related request when we know where it lives.
  Future<void> _open(
    BuildContext context,
    WidgetRef ref,
    AppNotification n,
  ) async {
    unawaited(ref.read(inboxControllerProvider.notifier).markRead(n));
    final tabs = ref.read(requestsTabProvider.notifier);
    switch (n.target) {
      case NotificationTarget.leave when n.entityId != null:
        await context.push(Routes.leaveDetail(n.entityId!));
      case NotificationTarget.correction:
        tabs.set(RequestTab.corrections);
        context.go(Routes.requests);
      case NotificationTarget.overtime:
        tabs.set(RequestTab.overtime);
        context.go(Routes.requests);
      case NotificationTarget.payslip when n.entityId != null:
        await context.push(Routes.payslipDetail(n.entityId!));
      case NotificationTarget.payslip:
        context.go(Routes.payslip);
      case NotificationTarget.document when n.entityId != null:
        await context.push(Routes.documentDetail(n.entityId!));
      case NotificationTarget.document:
        await context.push(Routes.documents);
      default:
        break;
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final inbox = ref.watch(inboxControllerProvider);
    final controller = ref.read(inboxControllerProvider.notifier);
    final hasUnread = (inbox.value?.unread ?? 0) > 0;

    return SubPage(
      title: context.l10n.notifTitle,
      actions: [
        if (hasUnread)
          AppTextButton(
            label: context.l10n.notifMarkAllRead,
            onPressed: () async {
              final ok = await controller.markAllRead();
              if (!ok && context.mounted) {
                showAppSnack(
                  context,
                  context.l10n.notifMarkAllFailed,
                  error: true,
                );
              }
            },
          ),
      ],
      child: inbox.when(
        skipLoadingOnRefresh: true,
        loading: () => const SkeletonList(itemHeight: 76),
        error: (e, _) => ErrorState(error: e, onRetry: controller.refresh),
        data: (state) => RefreshIndicator(
          onRefresh: controller.refresh,
          child: state.items.isEmpty
              ? LayoutBuilder(
                  builder: (context, box) => SingleChildScrollView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    child: SizedBox(
                      height: box.maxHeight,
                      child: EmptyState(
                        icon: Icons.notifications_none_rounded,
                        title: context.l10n.notifEmptyTitle,
                        message: context.l10n.notifEmptyMessage,
                      ),
                    ),
                  ),
                )
              : NotificationListener<ScrollNotification>(
                  onNotification: (s) {
                    if (s.metrics.extentAfter < 300) {
                      unawaited(controller.loadMore());
                    }
                    return false;
                  },
                  child: ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(
                      PeopleSpacing.page,
                      0,
                      PeopleSpacing.page,
                      PeopleSpacing.xl,
                    ),
                    itemCount: state.items.length + (state.hasMore ? 1 : 0),
                    separatorBuilder: (_, _) =>
                        const SizedBox(height: PeopleSpacing.xs),
                    itemBuilder: (context, i) {
                      if (i >= state.items.length) {
                        return const Padding(
                          padding: EdgeInsets.all(PeopleSpacing.md),
                          child: Center(
                            child: CircularProgressIndicator(strokeWidth: 2),
                          ),
                        );
                      }
                      final n = state.items[i];
                      return NotificationTile(
                        notification: n,
                        onTap: () => _open(context, ref, n),
                      );
                    },
                  ),
                ),
        ),
      ),
    );
  }
}

class NotificationTile extends StatelessWidget {
  const NotificationTile({
    super.key,
    required this.notification,
    required this.onTap,
  });

  final AppNotification notification;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final n = notification;
    final l10n = context.l10n;
    final (IconData icon, Color color) = switch (n.target) {
      NotificationTarget.leave => (Icons.beach_access_outlined, c.primaryText),
      NotificationTarget.correction => (
        Icons.edit_calendar_outlined,
        c.warningText,
      ),
      NotificationTarget.overtime => (Icons.more_time_rounded, c.infoText),
      NotificationTarget.payslip => (
        Icons.receipt_long_outlined,
        c.successText,
      ),
      NotificationTarget.document => (Icons.badge_outlined, c.infoText),
      NotificationTarget.none => (
        Icons.notifications_none_rounded,
        c.textSecondary,
      ),
    };
    final age = n.createdAt == null ? '' : ageText(l10n, n.createdAt!.utc);
    final title = n.titleFor(l10n);
    return AppCard(
      shadow: false,
      onTap: onTap,
      color: n.isRead ? c.surface : c.primarySoft.withValues(alpha: 0.55),
      borderColor: n.isRead ? c.border : c.primary.withValues(alpha: 0.25),
      semanticLabel: n.isRead
          ? l10n.notifSemantics(title, n.body, age)
          : l10n.notifSemanticsUnread(title, n.body, age),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconTile(
            icon: icon,
            color: color,
            circle: true,
            size: 40,
            iconSize: 20,
          ),
          const SizedBox(width: PeopleSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text(
                        title,
                        style: t.titleSmall!.copyWith(
                          fontWeight: n.isRead
                              ? FontWeight.w500
                              : FontWeight.w700,
                        ),
                      ),
                    ),
                    const SizedBox(width: PeopleSpacing.xs),
                    Text(
                      age,
                      style: t.labelSmall!.copyWith(color: c.textMuted),
                    ),
                    if (!n.isRead) ...[
                      const SizedBox(width: 6),
                      Container(
                        width: 8,
                        height: 8,
                        margin: const EdgeInsets.only(top: 4),
                        decoration: BoxDecoration(
                          color: c.primary,
                          shape: BoxShape.circle,
                        ),
                      ),
                    ],
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  n.body,
                  style: t.bodySmall!.copyWith(color: c.textSecondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
