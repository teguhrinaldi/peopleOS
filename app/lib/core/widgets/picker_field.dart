import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Tappable field that opens a date/time picker. Looks like [AppTextField]
/// (label above, error below) and reads as one button to screen readers.
class PickerField extends StatelessWidget {
  const PickerField({
    super.key,
    required this.label,
    required this.icon,
    required this.onTap,
    this.value,
    this.hint,
    this.errorText,
    this.onClear,
  });

  final String label;
  final IconData icon;
  final String? value;
  final String? hint;
  final String? errorText;
  final VoidCallback onTap;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final t = Theme.of(context).textTheme;
    final l10n = context.l10n;
    final hint = this.hint ?? l10n.commonSelect;
    final hasValue = value != null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Text(
            label,
            style: t.labelLarge!.copyWith(color: c.textSecondary, fontSize: 13),
          ),
        ),
        Semantics(
          container: true,
          button: true,
          label: '$label, ${value ?? hint}',
          excludeSemantics: true,
          child: InkWell(
            borderRadius: PeopleRadii.inputRadius,
            onTap: onTap,
            child: Container(
              constraints: const BoxConstraints(minHeight: 52),
              padding: const EdgeInsets.symmetric(horizontal: PeopleSpacing.md),
              decoration: BoxDecoration(
                color: c.surface,
                borderRadius: PeopleRadii.inputRadius,
                border: Border.all(
                  color: errorText != null ? c.error : c.border,
                ),
              ),
              child: Row(
                children: [
                  Icon(icon, size: 20, color: c.textMuted),
                  const SizedBox(width: PeopleSpacing.xs),
                  Expanded(
                    child: Text(
                      value ?? hint,
                      style: t.bodyLarge!.copyWith(
                        fontSize: 15,
                        color: hasValue ? c.textPrimary : c.textMuted,
                      ),
                    ),
                  ),
                  if (hasValue && onClear != null)
                    IconButton(
                      tooltip: l10n.commonClear(label),
                      constraints: const BoxConstraints(
                        minWidth: 40,
                        minHeight: 40,
                      ),
                      icon: const Icon(Icons.close_rounded, size: 18),
                      onPressed: onClear,
                    ),
                ],
              ),
            ),
          ),
        ),
        if (errorText != null)
          Padding(
            padding: const EdgeInsets.only(top: 6, left: 4),
            child: Text(
              errorText!,
              style: t.bodySmall!.copyWith(color: c.errorText),
            ),
          ),
      ],
    );
  }
}
