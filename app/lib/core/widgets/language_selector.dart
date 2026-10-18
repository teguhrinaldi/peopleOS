import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Language switch (Device default / English / Bahasa Indonesia). Applies
/// immediately, persists locally, and saves to the server user preference
/// when signed in. [compact] is the login-screen variant (text buttons).
class LanguageSelector extends ConsumerWidget {
  const LanguageSelector({super.key, this.compact = false});

  final bool compact;

  Future<void> _pick(BuildContext context, WidgetRef ref, String? code) async {
    final signedIn = ref.read(authControllerProvider).isSignedIn;
    final saved = await ref
        .read(localeControllerProvider.notifier)
        .choose(code, signedIn: signedIn);
    if (!saved && context.mounted) {
      // Speak the language just chosen; the tree has not rebuilt yet.
      final l10n = lookupAppLocalizations(ref.read(effectiveLocaleProvider));
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(l10n.languageSavedLocalOnly)));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final chosen = ref.watch(localeControllerProvider)?.languageCode;
    final l10n = context.l10n;
    final c = context.colors;
    final options = <(String?, String)>[
      if (!compact) (null, l10n.languageSystem),
      ('en', compact ? 'EN' : l10n.languageEnglish),
      ('id', compact ? 'ID' : l10n.languageIndonesian),
    ];
    return Semantics(
      container: true,
      label: l10n.languageTitle,
      child: Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          color: c.neutralSoft,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          mainAxisSize: compact ? MainAxisSize.min : MainAxisSize.max,
          children: [
            for (final (code, label) in options)
              Expanded(
                flex: compact ? 0 : 1,
                child: Semantics(
                  container: true,
                  button: true,
                  selected: code == chosen,
                  label: switch (code) {
                    'en' => l10n.languageEnglish,
                    'id' => l10n.languageIndonesian,
                    _ => l10n.languageSystem,
                  },
                  excludeSemantics: true,
                  child: GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: () => _pick(context, ref, code),
                    child: Container(
                      constraints: const BoxConstraints(
                        minHeight: kMinTap - 8,
                        minWidth: 48,
                      ),
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      alignment: Alignment.center,
                      decoration: BoxDecoration(
                        color: code == chosen ? c.primary : Colors.transparent,
                        borderRadius: BorderRadius.circular(11),
                      ),
                      child: Text(
                        label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelLarge!.copyWith(
                          color: code == chosen ? c.onPrimary : c.textSecondary,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
