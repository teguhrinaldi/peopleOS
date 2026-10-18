import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/peopleos_logo.dart';
import 'package:peopleos/l10n/l10n.dart';

class NavDestination {
  const NavDestination(this.icon, this.selectedIcon);
  final IconData icon;
  final IconData selectedIcon;
}

/// The five employee destinations (order is fixed by the product spec).
const navDestinations = <NavDestination>[
  NavDestination(Icons.home_outlined, Icons.home_rounded),
  NavDestination(Icons.timer_outlined, Icons.timer_rounded),
  NavDestination(Icons.assignment_outlined, Icons.assignment_rounded),
  NavDestination(Icons.receipt_long_outlined, Icons.receipt_long_rounded),
  NavDestination(Icons.person_outline_rounded, Icons.person_rounded),
];

/// Localized label of destination [index] (same order as [navDestinations]).
String navLabel(AppLocalizations l10n, int index) => switch (index) {
  0 => l10n.navHome,
  1 => l10n.navAttendance,
  2 => l10n.navRequests,
  3 => l10n.navPayslip,
  _ => l10n.navProfile,
};

class AppBottomNav extends StatelessWidget {
  const AppBottomNav({
    super.key,
    required this.selectedIndex,
    required this.onSelected,
    this.badges = const {},
  });

  final int selectedIndex;
  final ValueChanged<int> onSelected;

  /// destination index -> badge count
  final Map<int, int> badges;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return DecoratedBox(
      decoration: BoxDecoration(
        color: c.surface,
        border: Border(top: BorderSide(color: c.border)),
        boxShadow: PeopleElevation.card(Theme.of(context).brightness),
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 68,
          child: Row(
            children: [
              for (var i = 0; i < navDestinations.length; i++)
                Expanded(
                  child: _NavItem(
                    destination: navDestinations[i],
                    label: navLabel(context.l10n, i),
                    selected: i == selectedIndex,
                    badge: badges[i] ?? 0,
                    onTap: () => onSelected(i),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.destination,
    required this.label,
    required this.selected,
    required this.badge,
    required this.onTap,
  });

  final NavDestination destination;
  final String label;
  final bool selected;
  final int badge;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final color = selected ? c.primaryText : c.textMuted;
    return Semantics(
      container: true,
      button: true,
      selected: selected,
      label: label,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Stack(
              clipBehavior: Clip.none,
              children: [
                AnimatedContainer(
                  duration: PeopleMotion.of(context, PeopleMotion.medium),
                  width: 52,
                  height: 30,
                  decoration: BoxDecoration(
                    color: selected ? c.primarySoft : Colors.transparent,
                    borderRadius: BorderRadius.circular(15),
                  ),
                  child: Icon(
                    selected ? destination.selectedIcon : destination.icon,
                    size: 24,
                    color: color,
                  ),
                ),
                if (badge > 0)
                  Positioned(right: 6, top: -2, child: _Badge(count: badge)),
              ],
            ),
            const SizedBox(height: 2),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelSmall!.copyWith(
                color: color,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Badge extends StatelessWidget {
  const _Badge({required this.count});
  final int count;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Container(
      constraints: const BoxConstraints(minWidth: 16),
      height: 16,
      padding: const EdgeInsets.symmetric(horizontal: 4),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: c.error,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: c.surface, width: 1.5),
      ),
      child: Text(
        count > 9 ? '9+' : '$count',
        style: const TextStyle(
          color: Colors.white,
          fontSize: 10,
          fontWeight: FontWeight.w700,
          height: 1.1,
        ),
      ),
    );
  }
}

/// Tablet / desktop navigation: a slim rail with the logo mark on top.
class AppSideRail extends StatelessWidget {
  const AppSideRail({
    super.key,
    required this.selectedIndex,
    required this.onSelected,
    this.badges = const {},
    this.trailing,
  });

  final int selectedIndex;
  final ValueChanged<int> onSelected;
  final Map<int, int> badges;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Container(
      width: 96,
      decoration: BoxDecoration(
        color: c.surface,
        border: Border(right: BorderSide(color: c.border)),
      ),
      child: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: PeopleSpacing.lg),
            const PeopleOsMark(size: 40),
            const SizedBox(height: PeopleSpacing.xl),
            for (var i = 0; i < navDestinations.length; i++)
              SizedBox(
                height: 76,
                child: _NavItem(
                  destination: navDestinations[i],
                  label: navLabel(context.l10n, i),
                  selected: i == selectedIndex,
                  badge: badges[i] ?? 0,
                  onTap: () => onSelected(i),
                ),
              ),
            const Spacer(),
            ?trailing,
            const SizedBox(height: PeopleSpacing.md),
          ],
        ),
      ),
    );
  }
}
