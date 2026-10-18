import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/core/theme/people_typography.dart';

/// Builds [ThemeData] purely from design tokens so no Material default colour
/// or shape leaks into the UI.
class PeopleTheme {
  const PeopleTheme._();

  static ThemeData get light => _build(PeopleColors.light, Brightness.light);
  static ThemeData get dark => _build(PeopleColors.dark, Brightness.dark);

  static ThemeData _build(PeopleColors c, Brightness b) {
    final scheme = ColorScheme(
      brightness: b,
      primary: c.primary,
      onPrimary: c.onPrimary,
      primaryContainer: c.primarySoft,
      onPrimaryContainer: c.primaryText,
      secondary: c.navy,
      onSecondary: c.onNavy,
      secondaryContainer: c.neutralSoft,
      onSecondaryContainer: c.textPrimary,
      tertiary: c.success,
      onTertiary: Colors.white,
      error: c.error,
      onError: Colors.white,
      errorContainer: c.errorSoft,
      onErrorContainer: c.errorText,
      surface: c.surface,
      onSurface: c.textPrimary,
      onSurfaceVariant: c.textSecondary,
      surfaceContainerLowest: c.surface,
      surfaceContainerLow: c.surface,
      surfaceContainer: c.surfaceMuted,
      surfaceContainerHigh: c.surfaceMuted,
      surfaceContainerHighest: c.neutralSoft,
      outline: c.borderStrong,
      outlineVariant: c.border,
      shadow: Colors.black,
      scrim: c.scrim,
    );

    final text = PeopleTypography.textTheme(c.textPrimary, c.textSecondary);

    OutlineInputBorder outline(Color color, [double w = 1]) =>
        OutlineInputBorder(
          borderRadius: PeopleRadii.inputRadius,
          borderSide: BorderSide(color: color, width: w),
        );

    return ThemeData(
      useMaterial3: true,
      brightness: b,
      colorScheme: scheme,
      fontFamily: PeopleTypography.family,
      textTheme: text,
      primaryTextTheme: text,
      scaffoldBackgroundColor: c.background,
      canvasColor: c.background,
      dividerColor: c.border,
      splashFactory: InkRipple.splashFactory,
      // Visible keyboard focus (web/desktop) and hover feedback.
      focusColor: c.primary.withValues(alpha: 0.22),
      hoverColor: c.primary.withValues(alpha: 0.06),
      visualDensity: VisualDensity.standard,
      materialTapTargetSize: MaterialTapTargetSize.padded,
      extensions: [c],
      appBarTheme: AppBarTheme(
        backgroundColor: c.background,
        foregroundColor: c.textPrimary,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: text.titleLarge,
        systemOverlayStyle: b == Brightness.dark
            ? SystemUiOverlayStyle.light
            : SystemUiOverlayStyle.dark,
      ),
      cardTheme: CardThemeData(
        color: c.surface,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: PeopleRadii.cardRadius,
          side: BorderSide(color: c.border),
        ),
      ),
      dividerTheme: DividerThemeData(color: c.border, thickness: 1, space: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: c.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: PeopleSpacing.md,
          vertical: 14,
        ),
        hintStyle: text.bodyMedium!.copyWith(color: c.textMuted),
        labelStyle: text.bodyMedium!.copyWith(color: c.textSecondary),
        helperStyle: text.bodySmall!.copyWith(color: c.textMuted),
        errorStyle: text.bodySmall!.copyWith(color: c.errorText),
        prefixIconColor: c.textMuted,
        suffixIconColor: c.textMuted,
        border: outline(c.border),
        enabledBorder: outline(c.border),
        focusedBorder: outline(c.primary, 1.6),
        errorBorder: outline(c.error),
        focusedErrorBorder: outline(c.error, 1.6),
        disabledBorder: outline(c.border),
      ),
      checkboxTheme: CheckboxThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
        side: BorderSide(color: c.borderStrong, width: 1.5),
        fillColor: WidgetStateProperty.resolveWith(
          (s) =>
              s.contains(WidgetState.selected) ? c.primary : Colors.transparent,
        ),
        checkColor: WidgetStatePropertyAll(c.onPrimary),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected) ? Colors.white : c.textMuted,
        ),
        trackColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected) ? c.primary : c.neutralSoft,
        ),
        trackOutlineColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.selected)
              ? Colors.transparent
              : c.borderStrong,
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        modalBarrierColor: c.scrim,
        showDragHandle: false,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(
            top: Radius.circular(PeopleRadii.sheet),
          ),
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: c.surface,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(PeopleRadii.sheet),
        ),
        titleTextStyle: text.titleLarge,
        contentTextStyle: text.bodyMedium!.copyWith(color: c.textSecondary),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: c.navy,
        contentTextStyle: text.bodyMedium!.copyWith(color: c.onNavy),
        shape: RoundedRectangleBorder(borderRadius: PeopleRadii.buttonRadius),
      ),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: c.primary,
        linearTrackColor: c.neutralSoft,
      ),
      textSelectionTheme: TextSelectionThemeData(
        cursorColor: c.primary,
        selectionColor: c.primary.withValues(alpha: 0.25),
      ),
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.macOS: CupertinoPageTransitionsBuilder(),
          TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(),
        },
      ),
    );
  }
}
