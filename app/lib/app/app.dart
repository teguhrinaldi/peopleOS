import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/l10n/locale_controller.dart';
import 'package:peopleos/l10n/l10n.dart';
import 'package:peopleos/core/theme/people_theme.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';

class PeopleOsApp extends ConsumerStatefulWidget {
  const PeopleOsApp({super.key});

  @override
  ConsumerState<PeopleOsApp> createState() => _PeopleOsAppState();
}

class _PeopleOsAppState extends ConsumerState<PeopleOsApp>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Coming back to the app is a good moment to deliver queued attendance.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      ref.read(outboxControllerProvider.notifier).syncNow(force: false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    final themeMode = ref.watch(themeModeProvider);
    return MaterialApp.router(
      title: 'PeopleOS',
      debugShowCheckedModeBanner: false,
      theme: PeopleTheme.light,
      darkTheme: PeopleTheme.dark,
      themeMode: themeMode,
      locale: ref.watch(localeControllerProvider),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      // Unsupported device languages fall back to English.
      localeResolutionCallback: (device, supported) => resolveAppLocale(device),
      onGenerateTitle: (context) => context.l10n.appName,
      routerConfig: router,
      builder: (context, child) {
        // Honour the user's font size up to 1.3x so layouts never break.
        final scaler = MediaQuery.textScalerOf(context)
            .clamp(minScaleFactor: 1, maxScaleFactor: 1.3);
        return MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: scaler),
          child: child!,
        );
      },
    );
  }
}
