import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/l10n/age.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Shown whenever any value on screen came from the local cache because the
/// server could not be reached. States its age; disappears after the next
/// successful live read.
class StaleDataBanner extends ConsumerWidget {
  const StaleDataBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cache = ref.watch(jsonCacheProvider);
    return ValueListenableBuilder<DateTime?>(
      valueListenable: cache.staleSince,
      builder: (context, since, _) {
        if (since == null) return const SizedBox.shrink();
        final c = context.colors;
        final age = ageText(context.l10n, since);
        return Semantics(
          liveRegion: true,
          container: true,
          child: Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(
              horizontal: PeopleSpacing.page,
              vertical: PeopleSpacing.xs + 2,
            ),
            color: c.infoSoft,
            child: Row(
              children: [
                Icon(Icons.history_rounded, size: 18, color: c.infoText),
                const SizedBox(width: PeopleSpacing.xs),
                Expanded(
                  child: Text(
                    context.l10n.staleBanner(age),
                    style: Theme.of(context).textTheme.bodySmall!
                        .copyWith(color: c.infoText),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
