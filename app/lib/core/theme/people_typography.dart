import 'package:flutter/material.dart';

/// Inter type scale (bundled locally as an asset; no runtime font fetching).
class PeopleTypography {
  const PeopleTypography._();

  static const String family = 'Inter';

  static TextStyle _s(
    double size,
    FontWeight w,
    double height, [
    double ls = 0,
  ]) => TextStyle(
    fontFamily: family,
    fontSize: size,
    fontWeight: w,
    height: height / size,
    letterSpacing: ls,
  );

  static TextTheme textTheme(Color primary, Color secondary) {
    return TextTheme(
      displayLarge: _s(36, FontWeight.w700, 44, -0.5).copyWith(color: primary),
      displayMedium: _s(30, FontWeight.w700, 38, -0.4).copyWith(color: primary),
      headlineLarge: _s(26, FontWeight.w700, 34, -0.3).copyWith(color: primary),
      headlineMedium: _s(
        22,
        FontWeight.w700,
        30,
        -0.2,
      ).copyWith(color: primary),
      headlineSmall: _s(20, FontWeight.w600, 28, -0.1).copyWith(color: primary),
      titleLarge: _s(18, FontWeight.w600, 26).copyWith(color: primary),
      titleMedium: _s(16, FontWeight.w600, 24).copyWith(color: primary),
      titleSmall: _s(14, FontWeight.w600, 20).copyWith(color: primary),
      bodyLarge: _s(16, FontWeight.w400, 24).copyWith(color: primary),
      bodyMedium: _s(14, FontWeight.w400, 20).copyWith(color: primary),
      bodySmall: _s(13, FontWeight.w400, 18).copyWith(color: secondary),
      labelLarge: _s(14, FontWeight.w600, 20).copyWith(color: primary),
      labelMedium: _s(12, FontWeight.w500, 16, 0.1).copyWith(color: secondary),
      labelSmall: _s(11, FontWeight.w500, 14, 0.2).copyWith(color: secondary),
    );
  }
}
