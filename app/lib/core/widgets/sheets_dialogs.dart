import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Modal bottom sheet in the PeopleOS style: 24dp top radius, drag handle,
/// optional title, scroll-safe on small screens and width-capped on tablets.
Future<T?> showAppSheet<T>(
  BuildContext context, {
  String? title,
  required WidgetBuilder builder,
  bool isScrollControlled = true,
}) {
  return showModalBottomSheet<T>(
    context: context,
    // Above the tab bar, not inside the tab's own navigator.
    useRootNavigator: true,
    isScrollControlled: isScrollControlled,
    useSafeArea: true,
    constraints: const BoxConstraints(maxWidth: 560),
    builder: (context) {
      final c = context.colors;
      return SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.viewInsetsOf(context).bottom,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(
              PeopleSpacing.page,
              PeopleSpacing.sm,
              PeopleSpacing.page,
              PeopleSpacing.xl,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: c.borderStrong,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                ),
                if (title != null) ...[
                  const SizedBox(height: PeopleSpacing.md),
                  Semantics(
                    header: true,
                    child: Text(
                      title,
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                  ),
                ],
                const SizedBox(height: PeopleSpacing.md),
                builder(context),
              ],
            ),
          ),
        ),
      );
    },
  );
}

/// Confirmation dialog. Returns true when the user confirms.
Future<bool> confirmDialog(
  BuildContext context, {
  required String title,
  required String message,
  required String confirmLabel,
  String? cancelLabel,
  bool destructive = false,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) {
      final c = context.colors;
      return AlertDialog(
        insetPadding: const EdgeInsets.symmetric(
          horizontal: PeopleSpacing.page,
        ),
        title: Text(title),
        content: Text(message),
        actionsPadding: const EdgeInsets.fromLTRB(
          PeopleSpacing.md,
          0,
          PeopleSpacing.md,
          PeopleSpacing.md,
        ),
        actions: [
          Row(
            children: [
              Expanded(
                child: OutlineButton(
                  label: cancelLabel ?? context.l10n.commonCancel,
                  onPressed: () => Navigator.of(context).pop(false),
                ),
              ),
              const SizedBox(width: PeopleSpacing.sm),
              Expanded(
                child: destructive
                    ? _DestructiveConfirm(
                        label: confirmLabel,
                        color: c.error,
                        onPressed: () => Navigator.of(context).pop(true),
                      )
                    : PrimaryButton(
                        label: confirmLabel,
                        onPressed: () => Navigator.of(context).pop(true),
                      ),
              ),
            ],
          ),
        ],
      );
    },
  );
  return result ?? false;
}

class _DestructiveConfirm extends StatelessWidget {
  const _DestructiveConfirm({
    required this.label,
    required this.color,
    required this.onPressed,
  });

  final String label;
  final Color color;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    // #DC2626-ish on white keeps white text >= 4.5:1 in both themes.
    return Semantics(
      container: true,
      button: true,
      label: label,
      excludeSemantics: true,
      child: Material(
        color: const Color(0xFFDC2626),
        borderRadius: PeopleRadii.buttonRadius,
        child: InkWell(
          borderRadius: PeopleRadii.buttonRadius,
          onTap: onPressed,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 52),
            child: Center(
              child: Text(
                label,
                style: Theme.of(context).textTheme.labelLarge!
                    .copyWith(color: Colors.white, fontSize: 15),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

void showAppSnack(BuildContext context, String message, {bool error = false}) {
  final messenger = ScaffoldMessenger.of(context);
  messenger
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Row(
          children: [
            Icon(
              error ? Icons.error_outline_rounded : Icons.check_circle_outline,
              color: Colors.white,
              size: 20,
            ),
            const SizedBox(width: PeopleSpacing.xs),
            Expanded(child: Text(message)),
          ],
        ),
        backgroundColor: error ? const Color(0xFFB91C1C) : context.colors.navy,
      ),
    );
}
