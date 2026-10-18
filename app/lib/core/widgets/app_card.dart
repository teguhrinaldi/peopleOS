import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';

/// Surface with the design-system card treatment: 16dp radius, hairline
/// border, restrained shadow. Optionally tappable with a ripple.
class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(PeopleSpacing.md),
    this.onTap,
    this.color,
    this.borderColor,
    this.semanticLabel,
    this.shadow = true,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final VoidCallback? onTap;
  final Color? color;
  final Color? borderColor;
  final String? semanticLabel;
  final bool shadow;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final brightness = Theme.of(context).brightness;
    final radius = PeopleRadii.cardRadius;
    final body = Padding(padding: padding, child: child);
    return Semantics(
      container: true,
      button: onTap != null,
      label: semanticLabel,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: color ?? c.surface,
          borderRadius: radius,
          border: Border.all(color: borderColor ?? c.border),
          boxShadow: shadow ? PeopleElevation.card(brightness) : null,
        ),
        child: onTap == null
            ? body
            : Material(
                type: MaterialType.transparency,
                child: InkWell(borderRadius: radius, onTap: onTap, child: body),
              ),
      ),
    );
  }
}

/// Rounded square holding an icon; used for quick actions and list leading.
class IconTile extends StatelessWidget {
  const IconTile({
    super.key,
    required this.icon,
    this.color,
    this.background,
    this.size = 44,
    this.iconSize = 22,
    this.circle = false,
  });

  final IconData icon;
  final Color? color;
  final Color? background;
  final double size;
  final double iconSize;
  final bool circle;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final fg = color ?? c.primaryText;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: background ?? fg.withValues(alpha: 0.12),
        shape: circle ? BoxShape.circle : BoxShape.rectangle,
        borderRadius: circle ? null : BorderRadius.circular(size * 0.3),
      ),
      child: ExcludeSemantics(
        child: Icon(icon, size: iconSize, color: fg),
      ),
    );
  }
}
