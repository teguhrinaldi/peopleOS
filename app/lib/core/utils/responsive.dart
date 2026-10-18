import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_tokens.dart';

extension ResponsiveContext on BuildContext {
  double get screenWidth => MediaQuery.sizeOf(this).width;
  bool get isWide => screenWidth >= Breakpoints.tablet;
  bool get isDesktop => screenWidth >= Breakpoints.desktop;
}

/// Centers content and caps its width on tablets/desktop so lines stay
/// readable, while keeping the phone gutter.
class ContentWidth extends StatelessWidget {
  const ContentWidth({
    super.key,
    required this.child,
    this.maxWidth = Breakpoints.contentMax,
    this.shrinkHeight = false,
  });

  final Widget child;
  final double maxWidth;

  /// Wrap the child's height (needed inside bottom bars, which get loose
  /// constraints and would otherwise grow to the full screen height).
  final bool shrinkHeight;

  @override
  Widget build(BuildContext context) {
    return Align(
      alignment: Alignment.topCenter,
      heightFactor: shrinkHeight ? 1 : null,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: child,
      ),
    );
  }
}
