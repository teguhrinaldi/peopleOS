import 'package:flutter/material.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/l10n/l10n.dart';

class _StateView extends StatelessWidget {
  const _StateView({
    required this.icon,
    required this.tint,
    required this.title,
    this.message,
    this.action,
  });

  final IconData icon;
  final Color tint;
  final String title;
  final String? message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    final c = context.colors;
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(PeopleSpacing.xl),
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 360),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 72,
                height: 72,
                decoration: BoxDecoration(
                  color: tint.withValues(alpha: 0.12),
                  shape: BoxShape.circle,
                ),
                child: ExcludeSemantics(
                  child: Icon(icon, size: 32, color: tint),
                ),
              ),
              const SizedBox(height: PeopleSpacing.md),
              Text(title, style: t.titleMedium, textAlign: TextAlign.center),
              if (message != null) ...[
                const SizedBox(height: PeopleSpacing.xs),
                Text(
                  message!,
                  style: t.bodyMedium!.copyWith(color: c.textSecondary),
                  textAlign: TextAlign.center,
                ),
              ],
              if (action != null) ...[
                const SizedBox(height: PeopleSpacing.lg),
                action!,
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.title,
    this.message,
    this.icon = Icons.inbox_outlined,
    this.actionLabel,
    this.onAction,
  });

  final String title;
  final String? message;
  final IconData icon;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => _StateView(
    icon: icon,
    tint: context.colors.primaryText,
    title: title,
    message: message,
    action: actionLabel == null
        ? null
        : PrimaryButton(
            label: actionLabel!,
            onPressed: onAction,
            expand: false,
          ),
  );
}

/// Error with retry. Copy adapts to the failure family.
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, this.onRetry});

  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final l10n = context.l10n;
    final (IconData icon, String title, Color tint) = switch (error) {
      NoConnectionException() => (
        Icons.wifi_off_rounded,
        l10n.errOfflineTitle,
        c.warningText,
      ),
      RequestTimeoutException() => (
        Icons.hourglass_empty_rounded,
        l10n.errTimeoutTitle,
        c.warningText,
      ),
      ForbiddenException() => (
        Icons.lock_outline_rounded,
        l10n.errForbiddenTitle,
        c.errorText,
      ),
      NotFoundException() => (
        Icons.search_off_rounded,
        l10n.errNotFoundTitle,
        c.textSecondary,
      ),
      ServerException() => (
        Icons.cloud_off_outlined,
        l10n.errServerTitle,
        c.errorText,
      ),
      _ => (Icons.error_outline_rounded, l10n.errGenericTitle, c.errorText),
    };
    return _StateView(
      icon: icon,
      tint: tint,
      title: title,
      message: localizedErrorMessage(error, l10n),
      action: onRetry == null
          ? null
          : OutlineButton(
              label: l10n.commonTryAgain,
              icon: Icons.refresh_rounded,
              onPressed: onRetry,
              expand: false,
            ),
    );
  }
}
