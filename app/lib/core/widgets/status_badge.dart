import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/l10n/l10n.dart';

enum BadgeTone { success, warning, error, info, neutral, primary }

enum _BadgeKind {
  active,
  pending,
  approved,
  rejected,
  expired,
  late,
  onTime,
  working,
  offline,
}

/// Pill badge. The label always carries the meaning (never colour alone); an
/// optional leading icon reinforces it.
class StatusBadge extends StatelessWidget {
  const StatusBadge({
    super.key,
    required String this.label,
    required this.tone,
    this.icon,
    this.dense = false,
  }) : _kind = null;

  const StatusBadge._named(
    this._kind, {
    super.key,
    this.label,
    required this.tone,
    this.icon,
    this.dense = false,
  });

  /// Literal label; null for the named variants, which localize at build.
  final String? label;
  final _BadgeKind? _kind;
  final BadgeTone tone;
  final IconData? icon;
  final bool dense;

  // Named variants from the design system ------------------------------------
  const StatusBadge.active({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.active,
        key: key,
        tone: BadgeTone.success,
        dense: dense,
      );
  const StatusBadge.pending({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.pending,
        key: key,
        tone: BadgeTone.warning,
        dense: dense,
      );
  const StatusBadge.approved({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.approved,
        key: key,
        tone: BadgeTone.success,
        dense: dense,
      );
  const StatusBadge.rejected({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.rejected,
        key: key,
        tone: BadgeTone.error,
        dense: dense,
      );
  const StatusBadge.expired({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.expired,
        key: key,
        tone: BadgeTone.error,
        dense: dense,
      );

  /// [label] overrides the default "Late" (e.g. "Late 5m").
  const StatusBadge.late({Key? key, String? label, bool dense = false})
    : this._named(
        _BadgeKind.late,
        key: key,
        label: label,
        tone: BadgeTone.error,
        dense: dense,
      );
  const StatusBadge.onTime({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.onTime,
        key: key,
        tone: BadgeTone.success,
        dense: dense,
      );
  const StatusBadge.working({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.working,
        key: key,
        tone: BadgeTone.success,
        icon: Icons.circle,
        dense: dense,
      );
  const StatusBadge.offline({Key? key, bool dense = false})
    : this._named(
        _BadgeKind.offline,
        key: key,
        tone: BadgeTone.info,
        icon: Icons.cloud_off_outlined,
        dense: dense,
      );

  String _text(AppLocalizations l10n) =>
      label ??
      switch (_kind!) {
        _BadgeKind.active => l10n.badgeActive,
        _BadgeKind.pending => l10n.badgePending,
        _BadgeKind.approved => l10n.badgeApproved,
        _BadgeKind.rejected => l10n.badgeRejected,
        _BadgeKind.expired => l10n.badgeExpired,
        _BadgeKind.late => l10n.badgeLate,
        _BadgeKind.onTime => l10n.badgeOnTime,
        _BadgeKind.working => l10n.badgeWorking,
        _BadgeKind.offline => l10n.badgeOffline,
      };

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final (Color bg, Color fg) = switch (tone) {
      BadgeTone.success => (c.successSoft, c.successText),
      BadgeTone.warning => (c.warningSoft, c.warningText),
      BadgeTone.error => (c.errorSoft, c.errorText),
      BadgeTone.info => (c.infoSoft, c.infoText),
      BadgeTone.primary => (c.primarySoft, c.primaryText),
      BadgeTone.neutral => (c.neutralSoft, c.textSecondary),
    };
    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: dense ? 8 : 12,
        vertical: dense ? 3 : 5,
      ),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: icon == Icons.circle ? 7 : 13, color: fg),
            const SizedBox(width: 5),
          ],
          Flexible(
            child: Text(
              _text(context.l10n),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelMedium!.copyWith(
                color: fg,
                fontWeight: FontWeight.w600,
                fontSize: dense ? 11 : 12,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
