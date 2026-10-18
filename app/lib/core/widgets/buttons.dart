import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';

enum _Kind { primary, secondary, outline, text, danger }

/// Shared implementation of the four design-system buttons (+ a destructive
/// outline). All variants: 52dp tall, 12dp radius, loading + disabled states,
/// >= 48dp touch target.
class _AppButton extends StatelessWidget {
  const _AppButton({
    required this.kind,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  final _Kind kind;
  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final enabled = onPressed != null && !loading;

    late Color bg;
    late Color fg;
    BorderSide? border;
    switch (kind) {
      case _Kind.primary:
        bg = c.primary;
        fg = c.onPrimary;
      case _Kind.secondary:
        bg = c.navy;
        fg = c.onNavy;
      case _Kind.outline:
        bg = c.surface;
        fg = c.primaryText;
        border = BorderSide(color: c.borderStrong);
      case _Kind.danger:
        bg = c.errorSoft;
        fg = c.errorText;
        border = BorderSide(color: c.error.withValues(alpha: 0.35));
      case _Kind.text:
        bg = Colors.transparent;
        fg = c.primaryText;
    }
    if (!enabled && !loading) {
      bg = kind == _Kind.text ? bg : c.neutralSoft;
      fg = c.textMuted;
      border = kind == _Kind.outline ? BorderSide(color: c.border) : null;
    }

    final content = Row(
      mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (loading)
          SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2.4, color: fg),
          )
        else ...[
          if (icon != null) ...[
            Icon(icon, size: 20, color: fg),
            const SizedBox(width: PeopleSpacing.xs),
          ],
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelLarge!
                  .copyWith(color: fg, fontSize: 15),
            ),
          ),
        ],
      ],
    );

    return Semantics(
      container: true,
      button: true,
      enabled: enabled,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: bg,
        shape: RoundedRectangleBorder(
          borderRadius: PeopleRadii.buttonRadius,
          side: border ?? BorderSide.none,
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: enabled ? onPressed : null,
          child: ConstrainedBox(
            constraints: BoxConstraints(
              minHeight: kind == _Kind.text ? kMinTap : 52,
              minWidth: kMinTap,
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.lg),
              child: Center(
                widthFactor: expand ? null : 1,
                heightFactor: 1,
                child: content,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class PrimaryButton extends StatelessWidget {
  const PrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;

  @override
  Widget build(BuildContext context) => _AppButton(
    kind: _Kind.primary,
    label: label,
    onPressed: onPressed,
    icon: icon,
    loading: loading,
    expand: expand,
  );
}

/// Navy button (the "Sign in" button in the reference).
class SecondaryButton extends StatelessWidget {
  const SecondaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;

  @override
  Widget build(BuildContext context) => _AppButton(
    kind: _Kind.secondary,
    label: label,
    onPressed: onPressed,
    icon: icon,
    loading: loading,
    expand: expand,
  );
}

class OutlineButton extends StatelessWidget {
  const OutlineButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
    this.expand = true,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;
  final bool expand;

  @override
  Widget build(BuildContext context) => _AppButton(
    kind: _Kind.outline,
    label: label,
    onPressed: onPressed,
    icon: icon,
    loading: loading,
    expand: expand,
  );
}

class DangerButton extends StatelessWidget {
  const DangerButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.loading = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool loading;

  @override
  Widget build(BuildContext context) => _AppButton(
    kind: _Kind.danger,
    label: label,
    onPressed: onPressed,
    icon: icon,
    loading: loading,
  );
}

class AppTextButton extends StatelessWidget {
  const AppTextButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.expand = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final bool expand;

  @override
  Widget build(BuildContext context) => _AppButton(
    kind: _Kind.text,
    label: label,
    onPressed: onPressed,
    icon: icon,
    expand: expand,
  );
}
