import 'package:flutter/material.dart';

/// Semantic colour tokens (light + dark). Values are taken from the
/// PeopleOS design-system panel: Primary #2563EB, Success #10B981,
/// Warning #F59E0B, Error #EF4444, navy secondary, soft neutral surfaces.
///
/// Text-on-tint pairs (e.g. [successText] on [successSoft]) are chosen for
/// WCAG AA contrast; the raw brand hues are used for icons and fills only.
@immutable
class PeopleColors extends ThemeExtension<PeopleColors> {
  const PeopleColors({
    required this.background,
    required this.surface,
    required this.surfaceMuted,
    required this.border,
    required this.borderStrong,
    required this.textPrimary,
    required this.textSecondary,
    required this.textMuted,
    required this.primary,
    required this.primaryText,
    required this.primarySoft,
    required this.onPrimary,
    required this.navy,
    required this.onNavy,
    required this.success,
    required this.successText,
    required this.successSoft,
    required this.warning,
    required this.warningText,
    required this.warningSoft,
    required this.error,
    required this.errorText,
    required this.errorSoft,
    required this.info,
    required this.infoText,
    required this.infoSoft,
    required this.neutralSoft,
    required this.scrim,
    required this.splashTop,
    required this.splashBottom,
  });

  final Color background;
  final Color surface;
  final Color surfaceMuted;
  final Color border;
  final Color borderStrong;
  final Color textPrimary;
  final Color textSecondary;
  final Color textMuted;
  final Color primary;
  final Color primaryText;
  final Color primarySoft;
  final Color onPrimary;
  final Color navy;
  final Color onNavy;
  final Color success;
  final Color successText;
  final Color successSoft;
  final Color warning;
  final Color warningText;
  final Color warningSoft;
  final Color error;
  final Color errorText;
  final Color errorSoft;
  final Color info;
  final Color infoText;
  final Color infoSoft;
  final Color neutralSoft;
  final Color scrim;
  final Color splashTop;
  final Color splashBottom;

  static const light = PeopleColors(
    background: Color(0xFFF5F8FC),
    surface: Color(0xFFFFFFFF),
    surfaceMuted: Color(0xFFF1F5F9),
    border: Color(0xFFE5EAF2),
    borderStrong: Color(0xFFCBD5E1),
    textPrimary: Color(0xFF0F172A),
    textSecondary: Color(0xFF475569),
    textMuted: Color(0xFF5B6B82),
    primary: Color(0xFF2563EB),
    primaryText: Color(0xFF1D4ED8),
    primarySoft: Color(0xFFEAF1FF),
    onPrimary: Color(0xFFFFFFFF),
    navy: Color(0xFF0F2447),
    onNavy: Color(0xFFFFFFFF),
    success: Color(0xFF10B981),
    successText: Color(0xFF047857),
    successSoft: Color(0xFFDDF7EC),
    warning: Color(0xFFF59E0B),
    warningText: Color(0xFF9F4508),
    warningSoft: Color(0xFFFEF0D3),
    error: Color(0xFFEF4444),
    errorText: Color(0xFFB91C1C),
    errorSoft: Color(0xFFFDE4E4),
    info: Color(0xFF06B6D4),
    infoText: Color(0xFF0E7490),
    infoSoft: Color(0xFFD7F3F9),
    neutralSoft: Color(0xFFE9EEF5),
    scrim: Color(0x990F172A),
    splashTop: Color(0xFF12274A),
    splashBottom: Color(0xFF1C3860),
  );

  static const dark = PeopleColors(
    background: Color(0xFF0A1220),
    surface: Color(0xFF111B2E),
    surfaceMuted: Color(0xFF17233B),
    border: Color(0xFF243250),
    borderStrong: Color(0xFF34456A),
    textPrimary: Color(0xFFF1F5F9),
    textSecondary: Color(0xFFB4BFD2),
    textMuted: Color(0xFF8D9AB2),
    primary: Color(0xFF2563EB),
    primaryText: Color(0xFF7DB0FF),
    primarySoft: Color(0xFF162A52),
    onPrimary: Color(0xFFFFFFFF),
    navy: Color(0xFF1B3A6E),
    onNavy: Color(0xFFFFFFFF),
    success: Color(0xFF10B981),
    successText: Color(0xFF4FDDAE),
    successSoft: Color(0xFF0F3A31),
    warning: Color(0xFFF59E0B),
    warningText: Color(0xFFFBBF54),
    warningSoft: Color(0xFF41300F),
    error: Color(0xFFEF4444),
    errorText: Color(0xFFFF8E8E),
    errorSoft: Color(0xFF45181E),
    info: Color(0xFF06B6D4),
    infoText: Color(0xFF5AD8EE),
    infoSoft: Color(0xFF0E3A45),
    neutralSoft: Color(0xFF212F4A),
    scrim: Color(0xB3000000),
    splashTop: Color(0xFF0B1730),
    splashBottom: Color(0xFF14284A),
  );

  @override
  PeopleColors copyWith() => this;

  @override
  PeopleColors lerp(ThemeExtension<PeopleColors>? other, double t) {
    if (other is! PeopleColors) return this;
    return t < 0.5 ? this : other;
  }
}

extension PeopleColorsContext on BuildContext {
  PeopleColors get colors => Theme.of(this).extension<PeopleColors>()!;
}
