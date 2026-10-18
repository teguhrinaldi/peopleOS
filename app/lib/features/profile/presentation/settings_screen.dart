import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:peopleos/app/di.dart';
import 'package:peopleos/app/router.dart';
import 'package:peopleos/core/config/app_config.dart';
import 'package:peopleos/core/network/api_client.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/widgets/app_card.dart';
import 'package:peopleos/core/widgets/app_text_field.dart';
import 'package:peopleos/core/widgets/app_top_bar.dart';
import 'package:peopleos/core/widgets/buttons.dart';
import 'package:peopleos/core/widgets/list_widgets.dart';
import 'package:peopleos/core/widgets/sheets_dialogs.dart';
import 'package:peopleos/features/attendance/presentation/attendance_providers.dart';
import 'package:peopleos/features/attendance/presentation/outbox_controller.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/features/demo/demo_services.dart';
import 'package:peopleos/core/widgets/language_selector.dart';
import 'package:peopleos/features/profile/presentation/profile_labels.dart';
import 'package:peopleos/features/requests/presentation/requests_screen.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Demo evaluation switches, mirrored into [AppSettings].
class DemoControls {
  const DemoControls({
    required this.scenario,
    required this.outside,
    required this.offline,
  });
  final DemoScenario scenario;
  final bool outside;
  final bool offline;
}

class DemoControlsNotifier extends Notifier<DemoControls> {
  AppSettings get _s => ref.read(appSettingsProvider);

  @override
  DemoControls build() {
    final s = ref.watch(appSettingsProvider);
    return DemoControls(
      scenario: s.demoScenario,
      outside: s.demoOutsideArea,
      offline: s.demoOffline,
    );
  }

  Future<void> setScenario(DemoScenario v) async {
    await _s.setDemoScenario(v);
    state = DemoControls(
      scenario: v,
      outside: state.outside,
      offline: state.offline,
    );
    ref.invalidate(serverTodayProvider);
    await ref.read(authControllerProvider.notifier).refreshProfile();
  }

  Future<void> setOutside(bool v) async {
    await _s.setDemoOutsideArea(v);
    state = DemoControls(
      scenario: state.scenario,
      outside: v,
      offline: state.offline,
    );
  }

  Future<void> setOffline(bool v) async {
    await _s.setDemoOffline(v);
    state = DemoControls(
      scenario: state.scenario,
      outside: state.outside,
      offline: v,
    );
    final connectivity = ref.read(connectivityServiceProvider);
    if (connectivity is DemoConnectivityService) {
      await connectivity.notifyChanged();
    }
    ref.invalidate(serverTodayProvider);
    if (!v) unawaited(ref.read(outboxControllerProvider.notifier).syncNow());
  }
}

final demoControlsProvider =
    NotifierProvider<DemoControlsNotifier, DemoControls>(
      DemoControlsNotifier.new,
    );

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final c = context.colors;
    final mode = ref.watch(themeModeProvider);
    final version = ref.watch(appVersionProvider);
    final company = ref.watch(authControllerProvider).session?.company;
    final t = Theme.of(context).textTheme;
    final l = context.l10n;

    return SubPage(
      title: l.settingsTitle,
      child: ListView(
        padding: const EdgeInsets.all(PeopleSpacing.page),
        children: [
          _Label(l.settingsAppearance),
          AppCard(
            padding: const EdgeInsets.all(PeopleSpacing.md),
            child: SegmentedTabs<ThemeMode>(
              value: mode,
              onChanged: (m) =>
                  unawaited(ref.read(themeModeProvider.notifier).set(m)),
              options: {
                ThemeMode.light: l.settingsThemeLight,
                ThemeMode.dark: l.settingsThemeDark,
                ThemeMode.system: l.settingsThemeSystem,
              },
            ),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          _Label(l.languageTitle),
          const AppCard(
            padding: EdgeInsets.all(PeopleSpacing.md),
            child: LanguageSelector(),
          ),
          const SizedBox(height: PeopleSpacing.lg),
          _Label(l.settingsGeneral),
          AppCard(
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                AppListRow(
                  title: l.settingsSyncStatus,
                  subtitle: l.settingsSyncSubtitle,
                  leading: const Icon(Icons.sync_rounded),
                  onTap: () => context.push(Routes.sync),
                ),
                if (AppConfig.useApi) ...[
                  const RowDivider(),
                  AppListRow(
                    title: l.settingsServerAddress,
                    subtitle: ref.watch(serverUrlProvider) ?? l.settingsNotSet,
                    leading: const Icon(Icons.dns_outlined),
                    onTap: () => _editServer(context, ref),
                  ),
                ],
              ],
            ),
          ),
          if (!AppConfig.useApi) ...[
            const SizedBox(height: PeopleSpacing.lg),
            _Label(l.settingsDemoControls),
            const _DemoPanel(),
          ],
          const SizedBox(height: PeopleSpacing.lg),
          _Label(l.settingsAbout),
          AppCard(
            padding: const EdgeInsets.all(PeopleSpacing.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _AboutLine(l.settingsAboutApp, l.settingsAppName),
                _AboutLine(l.settingsAboutVersion, version),
                _AboutLine(
                  l.settingsAboutMode,
                  AppConfig.useApi ? l.settingsModeServer : l.settingsModeDemo,
                ),
                if (company != null)
                  _AboutLine(l.settingsAboutCompany, company.name),
                if (company != null)
                  _AboutLine(l.settingsAboutTimezone, company.timezone),
                const SizedBox(height: PeopleSpacing.xs),
                Text(
                  l.authTagline,
                  style: t.bodySmall!.copyWith(color: c.textMuted),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _editServer(BuildContext context, WidgetRef ref) async {
    final controller = TextEditingController(
      text: ref.read(serverUrlProvider) ?? '',
    );
    await showAppSheet<void>(
      context,
      title: context.l10n.settingsServerAddress,
      builder: (sheetContext) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          AppTextField(
            label: context.l10n.settingsAddress,
            controller: controller,
            hint: context.l10n.settingsServerHint,
            helper: context.l10n.settingsServerHelper,
            keyboardType: TextInputType.url,
          ),
          const SizedBox(height: PeopleSpacing.lg),
          PrimaryButton(
            label: context.l10n.commonSave,
            onPressed: () async {
              final url = ApiClient.normalizeBaseUrl(controller.text);
              if (url == null) return;
              await ref.read(serverUrlProvider.notifier).save(url);
              if (sheetContext.mounted) Navigator.of(sheetContext).pop();
              if (context.mounted) {
                showAppSnack(context, context.l10n.settingsServerSaved);
              }
            },
          ),
        ],
      ),
    );
    controller.dispose();
  }
}

class _Label extends StatelessWidget {
  // ignore: use_super_parameters
  const _Label(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(left: 4, bottom: PeopleSpacing.xs),
    child: Text(
      text.toUpperCase(),
      style: Theme.of(context).textTheme.labelMedium!
          .copyWith(letterSpacing: 0.8, fontWeight: FontWeight.w700),
    ),
  );
}

class _AboutLine extends StatelessWidget {
  const _AboutLine(this.k, this.v);
  final String k;
  final String v;

  @override
  Widget build(BuildContext context) {
    final t = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 90,
            child: Text(
              k,
              style: t.bodyMedium!.copyWith(color: context.colors.textMuted),
            ),
          ),
          Expanded(child: Text(v, style: t.bodyMedium)),
        ],
      ),
    );
  }
}

class _DemoPanel extends ConsumerWidget {
  const _DemoPanel();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final controls = ref.watch(demoControlsProvider);
    final notifier = ref.read(demoControlsProvider.notifier);
    final t = Theme.of(context).textTheme;
    final l = context.l10n;
    return AppCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          AppListRow(
            title: l.settingsScenario,
            subtitle: demoScenarioLabel(l, controls.scenario),
            leading: const Icon(Icons.science_outlined),
            onTap: () => showAppSheet<void>(
              context,
              title: l.settingsDemoScenario,
              builder: (sheetContext) => Column(
                children: [
                  for (final s in DemoScenario.values)
                    AppListRow(
                      title: demoScenarioLabel(l, s),
                      showChevron: false,
                      trailing: s == controls.scenario
                          ? Icon(
                              Icons.check_circle_rounded,
                              color: context.colors.primary,
                            )
                          : null,
                      onTap: () {
                        unawaited(notifier.setScenario(s));
                        Navigator.of(sheetContext).pop();
                      },
                    ),
                ],
              ),
            ),
          ),
          const RowDivider(),
          _SwitchRow(
            title: l.settingsSimOutside,
            subtitle: l.settingsSimOutsideSub,
            value: controls.outside,
            onChanged: (v) => unawaited(notifier.setOutside(v)),
          ),
          const RowDivider(),
          _SwitchRow(
            title: l.settingsSimOffline,
            subtitle: l.settingsSimOfflineSub,
            value: controls.offline,
            onChanged: (v) => unawaited(notifier.setOffline(v)),
          ),
          const RowDivider(),
          AppListRow(
            title: l.settingsResetDemo,
            subtitle: l.settingsResetDemoSub,
            leading: const Icon(Icons.restart_alt_rounded),
            onTap: () async {
              final ok = await confirmDialog(
                context,
                title: l.settingsResetDemoTitle,
                message: l.settingsResetDemoMessage,
                confirmLabel: l.settingsReset,
                destructive: true,
              );
              if (!ok) return;
              await ref.read(demoBackendProvider).reset();
              final outbox = ref.read(outboxControllerProvider);
              for (final item in outbox.items) {
                await ref
                    .read(outboxControllerProvider.notifier)
                    .discard(item.idempotencyKey);
              }
              ref
                ..invalidate(serverTodayProvider)
                ..invalidate(attendanceHistoryProvider);
              if (context.mounted) {
                showAppSnack(context, l.settingsDemoDataReset);
              }
            },
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(
              PeopleSpacing.md,
              0,
              PeopleSpacing.md,
              PeopleSpacing.sm,
            ),
            child: Text(l.settingsDemoModeNote, style: t.bodySmall),
          ),
        ],
      ),
    );
  }
}

class _SwitchRow extends StatelessWidget {
  const _SwitchRow({
    required this.title,
    required this.subtitle,
    required this.value,
    required this.onChanged,
  });

  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) => MergeSemantics(
    child: AppListRow(
      title: title,
      subtitle: subtitle,
      showChevron: false,
      trailing: Switch(value: value, onChanged: onChanged),
      onTap: () => onChanged(!value),
    ),
  );
}
