import 'package:flutter/material.dart';

/// 4/8 spacing rhythm.
class PeopleSpacing {
  const PeopleSpacing._();
  static const double xxs = 4;
  static const double xs = 8;
  static const double sm = 12;
  static const double md = 16;
  static const double lg = 20;
  static const double xl = 24;
  static const double xxl = 32;
  static const double huge = 48;

  /// Horizontal page gutter on phones.
  static const double page = 20;
}

class PeopleRadii {
  const PeopleRadii._();
  static const double chip = 999;
  static const double input = 12;
  static const double button = 12;
  static const double card = 16;
  static const double sheet = 24;
  static const double tile = 14;

  static BorderRadius get inputRadius => BorderRadius.circular(input);
  static BorderRadius get buttonRadius => BorderRadius.circular(button);
  static BorderRadius get cardRadius => BorderRadius.circular(card);
  static BorderRadius get chipRadius => BorderRadius.circular(chip);
  static BorderRadius get tileRadius => BorderRadius.circular(tile);
}

class PeopleElevation {
  const PeopleElevation._();

  /// Restrained card shadow; dark mode relies on borders instead.
  static List<BoxShadow> card(Brightness b) => b == Brightness.dark
      ? const []
      : const [
          BoxShadow(
            color: Color(0x0A0F172A),
            blurRadius: 2,
            offset: Offset(0, 1),
          ),
          BoxShadow(
            color: Color(0x0D0F172A),
            blurRadius: 16,
            offset: Offset(0, 6),
          ),
        ];

  static List<BoxShadow> raised(Brightness b) => b == Brightness.dark
      ? const []
      : const [
          BoxShadow(
            color: Color(0x1A0F172A),
            blurRadius: 24,
            offset: Offset(0, 10),
          ),
        ];
}

class Breakpoints {
  const Breakpoints._();
  static const double tablet = 600;
  static const double desktop = 1024;
  static const double contentMax = 1080;
  static const double formMax = 520;
}

/// Minimum interactive size (Material / WCAG target size guidance).
const double kMinTap = 48;

/// Motion tokens. Movement is subtle and always disabled when the OS asks
/// for reduced motion (`MediaQuery.disableAnimations`).
class PeopleMotion {
  const PeopleMotion._();
  static const Duration fast = Duration(milliseconds: 120);
  static const Duration medium = Duration(milliseconds: 200);
  static const Duration slow = Duration(milliseconds: 320);
  static const Curve standard = Curves.easeOutCubic;
  static const Curve emphasized = Curves.easeOutBack;

  static bool reduced(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;

  /// [d], or zero when reduced motion is requested.
  static Duration of(BuildContext context, Duration d) =>
      reduced(context) ? Duration.zero : d;
}
