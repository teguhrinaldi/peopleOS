import 'dart:ui';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/l10n/generated/app_localizations.dart';

/// Languages the app ships. English is the documented fallback for anything
/// missing and for unsupported device languages.
const supportedLanguageCodes = ['en', 'id'];

Locale resolveAppLocale(Locale? device) {
  final code = device?.languageCode;
  return supportedLanguageCodes.contains(code)
      ? Locale(code!)
      : const Locale('en');
}

/// The user's explicit language choice (`null` = follow the device).
/// Persisted locally; in API mode it is also saved to the server user
/// preference (see AuthController). Changing it never restarts the app.
class LocaleController extends Notifier<Locale?> {
  @override
  Locale? build() {
    final saved = ref.watch(appSettingsProvider).localeCode;
    return supportedLanguageCodes.contains(saved) ? Locale(saved!) : null;
  }

  /// Applies the server's saved preference after sign-in: the server wins
  /// when it has one; otherwise a local choice is pushed up.
  Future<void> reconcileWithServer(String? serverLocale) async {
    if (supportedLanguageCodes.contains(serverLocale)) {
      if (state?.languageCode != serverLocale) await set(serverLocale);
    } else if (state != null) {
      await ref
          .read(authRepositoryProvider)
          .savePreferredLocale(state!.languageCode);
    }
  }

  /// User picked a language: apply now, persist locally and (when signed in,
  /// API mode) on the server. Returns false when the server did not store it
  /// (the choice still applies on this device).
  Future<bool> choose(String? code, {required bool signedIn}) async {
    await set(code);
    if (!signedIn) return true;
    return ref.read(authRepositoryProvider).savePreferredLocale(code);
  }

  /// `null` = device default.
  Future<void> set(String? code) async {
    final valid = supportedLanguageCodes.contains(code) ? code : null;
    state = valid == null ? null : Locale(valid);
    await ref.read(appSettingsProvider).setLocaleCode(valid);
  }
}

final localeControllerProvider = NotifierProvider<LocaleController, Locale?>(
  LocaleController.new,
);

/// The locale actually in use (choice, else device language, else English).
final effectiveLocaleProvider = Provider<Locale>((ref) {
  final chosen = ref.watch(localeControllerProvider);
  return chosen ?? resolveAppLocale(PlatformDispatcher.instance.locale);
});

const supportedAppLocales = AppLocalizations.supportedLocales;
