import 'package:flutter/material.dart';
import 'package:peopleos/core/error/app_exception.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';

/// Maps API field names (e.g. `requested_check_in_at`) to local form keys.
/// Unknown fields are dropped so the caller shows the generic banner.
Map<String, String> mapServerFieldErrors(
  ValidationException e,
  Map<String, String> fieldToKey,
) {
  final out = <String, String>{};
  for (final entry in e.fieldErrors.entries) {
    final key = fieldToKey[entry.key];
    if (key != null && entry.value.isNotEmpty) {
      out.putIfAbsent(key, () => entry.value.first);
    }
  }
  return out;
}

class FormErrorBanner extends StatelessWidget {
  const FormErrorBanner({super.key, required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Semantics(
      liveRegion: true,
      container: true,
      child: Container(
        padding: const EdgeInsets.all(PeopleSpacing.sm + 2),
        decoration: BoxDecoration(
          color: c.errorSoft,
          borderRadius: PeopleRadii.inputRadius,
          border: Border.all(color: c.error.withValues(alpha: 0.3)),
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(Icons.error_outline_rounded, color: c.errorText, size: 20),
            const SizedBox(width: PeopleSpacing.xs),
            Expanded(
              child: Text(
                message,
                style: Theme.of(context).textTheme.bodyMedium!
                    .copyWith(color: c.errorText),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
