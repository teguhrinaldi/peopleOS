import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Small non-secret preferences (theme, server URL, remembered email, demo
/// switches). Secrets such as the bearer token live in [TokenStore].
class AppSettings {
  AppSettings(this._prefs);

  final SharedPreferences _prefs;

  static const _kTheme = 'settings.theme_mode';
  static const _kLocale = 'settings.locale';
  static const _kServer = 'settings.server_url';
  static const _kEmail = 'settings.remembered_email';
  static const _kDemoOutside = 'settings.demo.outside_area';
  static const _kDemoOffline = 'settings.demo.offline';
  static const _kDemoScenario = 'settings.demo.scenario';

  ThemeMode get themeMode {
    switch (_prefs.getString(_kTheme)) {
      case 'light':
        return ThemeMode.light;
      case 'dark':
        return ThemeMode.dark;
    }
    return ThemeMode.system;
  }

  Future<void> setThemeMode(ThemeMode mode) =>
      _prefs.setString(_kTheme, mode.name);

  /// 'en' | 'id'; null = follow the device language.
  String? get localeCode => _prefs.getString(_kLocale);

  Future<void> setLocaleCode(String? code) =>
      code == null ? _prefs.remove(_kLocale) : _prefs.setString(_kLocale, code);

  String? get serverUrl => _prefs.getString(_kServer);

  Future<void> setServerUrl(String url) => _prefs.setString(_kServer, url);

  String? get rememberedEmail => _prefs.getString(_kEmail);

  Future<void> setRememberedEmail(String? email) =>
      email == null ? _prefs.remove(_kEmail) : _prefs.setString(_kEmail, email);

  bool get demoOutsideArea => _prefs.getBool(_kDemoOutside) ?? false;
  Future<void> setDemoOutsideArea(bool v) => _prefs.setBool(_kDemoOutside, v);

  DemoScenario get demoScenario => DemoScenario.values.firstWhere(
    (s) => s.name == _prefs.getString(_kDemoScenario),
    orElse: () => DemoScenario.normal,
  );
  Future<void> setDemoScenario(DemoScenario s) =>
      _prefs.setString(_kDemoScenario, s.name);

  bool get demoOffline => _prefs.getBool(_kDemoOffline) ?? false;
  Future<void> setDemoOffline(bool v) => _prefs.setBool(_kDemoOffline, v);
}

/// Demo-mode situations a buyer can switch to when evaluating the app.
enum DemoScenario {
  normal('Normal working day'),
  selfieRequired('Selfie required (strict policy)'),
  restDay('Not a working day'),
  noSchedule('No schedule assigned'),
  onLeave('On approved leave today'),
  terminated('Terminated employee (blocked)');

  const DemoScenario(this.label);
  final String label;
}
