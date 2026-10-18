import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/avatar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/core/widgets/status_badge.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/auth/domain/session.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/notifications/presentation/notifications_controller.dart';
import 'package:peopleos/l10n/l10n.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  Future<void> _logout(BuildContext context, WidgetRef ref) async {
    final unsynced = ref.read(outboxControllerProvider).pendingCount;
    final ok = await confirmDialog(
      context,
      title: context.l10n.profileSignOutTitle,
      message: unsynced > 0
          ? context.l10n.profileSignOutPending(unsynced)
          : context.l10n.profileSignOutMessage,
      confirmLabel: context.l10n.profileSignOutAction,
      destructive: true,
    );
    if (ok) await ref.read(authControllerProvider.notifier).logout();
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final employee = ref.watch(authControllerProvider).employee;
    if (employee == null) return const SizedBox.shrink();
    final unread = ref.watch(unreadCountProvider).value ?? 0;

    return SafeArea(
      bottom: false,
      child: ContentWidth(
        maxWidth: Breakpoints.formMax + 80,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            PeopleSpacing.page,
            PeopleSpacing.md,
            PeopleSpacing.page,
            PeopleSpacing.xl,
          ),
          children: [
            _ProfileHeader(employee: employee),
            const SizedBox(height: PeopleSpacing.lg),
            AppCard(
              padding: EdgeInsets.zero,
              child: Column(
                children: [
                  _NavRow(
                    icon: Icons.person_outline_rounded,
                    title: context.l10n.profilePersonalInfo,
                    onTap: () => context.push(Routes.profileDetail('personal')),
                  ),
                  const RowDivider(indent: 68),
                  _NavRow(
                    icon: Icons.badge_outlined,
                    title: context.l10n.profileEmployment,
                    onTap: () =>
                        context.push(Routes.profileDetail('employment')),
                  ),
                  const RowDivider(indent: 68),
                  _NavRow(
                    icon: Icons.business_center_outlined,
                    title: context.l10n.profileWorkInfo,
                    onTap: () => context.push(Routes.profileDetail('work')),
                  ),
                  const RowDivider(indent: 68),
                  _NavRow(
                    icon: Icons.folder_open_outlined,
                    title: context.l10n.profileDocuments,
                    onTap: () => context.push(Routes.documents),
                  ),
                  const RowDivider(indent: 68),
                  _NavRow(
                    icon: Icons.notifications_none_rounded,
                    title: context.l10n.profileNotifications,
                    trailing: unread > 0
                        ? StatusBadge(
                            label: context.l10n.profileNewCount(unread),
                            tone: BadgeTone.primary,
                            dense: true,
                          )
                        : null,
                    onTap: () => context.push(Routes.notifications),
                  ),
                  const RowDivider(indent: 68),
                  _NavRow(
                    icon: Icons.settings_outlined,
                    title: context.l10n.profileSettings,
                    onTap: () => context.push(Routes.settings),
                  ),
                ],
              ),
            ),
            const SizedBox(height: PeopleSpacing.xl),
            DangerButton(
              label: context.l10n.profileLogout,
              icon: Icons.logout_rounded,
              onPressed: () => _logout(context, ref),
            ),
          ],
        ),
      ),
    );
  }
}

class _NavRow extends StatelessWidget {
  const _NavRow({
    required this.icon,
    required this.title,
    required this.onTap,
    this.trailing,
  });

  final IconData icon;
  final String title;
  final VoidCallback onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => AppListRow(
    title: title,
    leading: Icon(icon, size: 24, color: context.colors.primaryText),
    trailing: trailing,
    onTap: onTap,
  );
}

class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({required this.employee});
  final Employee employee;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final c = context.colors;
    final l = context.l10n;
    final (String label, BadgeTone tone) = switch (employee.status) {
      'ACTIVE' => (l.profileStatusActive, BadgeTone.success),
      'PENDING' => (l.profileStatusPending, BadgeTone.warning),
      'TERMINATED' => (l.profileStatusTerminated, BadgeTone.error),
      _ => (l.profileStatusInactive, BadgeTone.neutral),
    };
    return Row(
      children: [
        Avatar(name: employee.fullName, photoUrl: employee.photoUrl, size: 80),
        const SizedBox(width: PeopleSpacing.md),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Semantics(
                header: true,
                child: Text(employee.fullName, style: t.headlineSmall),
              ),
              Text(
                employee.employeeNumber,
                style: t.bodyMedium!.copyWith(color: c.textMuted),
              ),
              const SizedBox(height: PeopleSpacing.xs),
              Align(
                alignment: Alignment.centerLeft,
                child: StatusBadge(
                  label: label,
                  tone: tone,
                  icon: Icons.verified_user_outlined,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
