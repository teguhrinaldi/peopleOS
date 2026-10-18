import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/utils/responsive.dart';
import 'package:peopleos/core/widgets/connectivity_widgets.dart';
import 'package:peopleos/core/widgets/navigation.dart';
import 'package:peopleos/core/widgets/stale_banner.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';

/// Adaptive frame around the five tabs: bottom navigation on phones, a side
/// rail from 600dp up. The offline banner sits above the content.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  void _select(int index) =>
      shell.goBranch(index, initialLocation: index == shell.currentIndex);

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final online = ref.watch(isOnlineProvider);
    final pending = ref.watch(
      outboxControllerProvider.select((s) => s.pendingCount),
    );

    final content = Column(
      children: [
        if (!online) OfflineBanner(pendingCount: pending),
        const StaleDataBanner(),
        Expanded(child: shell),
      ],
    );

    if (context.isWide) {
      return Scaffold(
        body: Row(
          children: [
            AppSideRail(selectedIndex: shell.currentIndex, onSelected: _select),
            Expanded(child: content),
          ],
        ),
      );
    }
    return Scaffold(
      body: SafeArea(bottom: false, child: content),
      bottomNavigationBar: AppBottomNav(
        selectedIndex: shell.currentIndex,
        onSelected: _select,
      ),
    );
  }
}
