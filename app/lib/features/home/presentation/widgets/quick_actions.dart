import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';

class QuickAction {
  const QuickAction(this.label, this.icon, this.color, this.onTap);
  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;
}

/// 3-column grid of shortcut tiles (Leave, Payslip, Attendance, ...).
class QuickActionsGrid extends StatelessWidget {
  const QuickActionsGrid({super.key, required this.actions});

  final List<QuickAction> actions;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, box) {
        const gap = PeopleSpacing.sm;
        final width = (box.maxWidth - gap * 2) / 3;
        return Wrap(
          spacing: gap,
          runSpacing: gap,
          children: [
            for (final a in actions)
              SizedBox(
                width: width,
                child: _Tile(action: a),
              ),
          ],
        );
      },
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.action});
  final QuickAction action;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return AppCard(
      onTap: action.onTap,
      semanticLabel: action.label,
      padding: const EdgeInsets.symmetric(
        vertical: PeopleSpacing.md,
        horizontal: 4,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          IconTile(icon: action.icon, color: action.color, size: 44),
          const SizedBox(height: PeopleSpacing.xs),
          Text(
            action.label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: t.labelLarge!.copyWith(fontWeight: FontWeight.w500),
          ),
        ],
      ),
    );
  }
}

/// Distinct tile hues for the quick actions (drawn from the palette).
class ActionColors {
  const ActionColors._();
  static const blue = Color(0xFF2563EB);
  static const orange = Color(0xFFF59E0B);
  static const teal = Color(0xFF0D9488);
  static const cyan = Color(0xFF0891B2);
  static const purple = Color(0xFF7C3AED);
}
