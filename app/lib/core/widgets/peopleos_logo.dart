import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';

/// PeopleOS brand mark: three people (two small, one central) drawn as
/// vectors so no raster asset is required. Matches the blue cluster mark in
/// the reference (splash, login, web admin).
class PeopleOsMark extends StatelessWidget {
  const PeopleOsMark({super.key, this.size = 48, this.mono});

  final double size;

  /// Single colour override (e.g. white on navy).
  final Color? mono;

  @override
  Widget build(BuildContext context) {
    return ExcludeSemantics(
      child: CustomPaint(size: Size.square(size), painter: _MarkPainter(mono)),
    );
  }
}

class _MarkPainter extends CustomPainter {
  _MarkPainter(this.mono);
  final Color? mono;

  @override
  void paint(Canvas canvas, Size s) {
    final w = s.width;
    final light = Paint()..color = mono ?? const Color(0xFF60A5FA);
    final mid = Paint()..color = mono ?? const Color(0xFF3B82F6);
    final deep = Paint()..color = mono ?? const Color(0xFF2563EB);

    // Left and right people.
    for (final (double cx, Paint p) in [(0.2, light), (0.8, light)]) {
      canvas.drawCircle(Offset(w * cx, w * 0.36), w * 0.1, p);
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromCenter(
            center: Offset(w * cx, w * 0.66),
            width: w * 0.2,
            height: w * 0.36,
          ),
          Radius.circular(w * 0.1),
        ),
        p,
      );
    }
    // Two small heads above.
    canvas.drawCircle(Offset(w * 0.36, w * 0.13), w * 0.07, mid);
    canvas.drawCircle(Offset(w * 0.64, w * 0.13), w * 0.07, mid);
    // Central person.
    canvas.drawCircle(Offset(w * 0.5, w * 0.38), w * 0.13, deep);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(
          center: Offset(w * 0.5, w * 0.76),
          width: w * 0.34,
          height: w * 0.46,
        ),
        Radius.circular(w * 0.17),
      ),
      deep,
    );
  }

  @override
  bool shouldRepaint(_MarkPainter old) => old.mono != mono;
}

/// Mark + "PeopleOS" wordmark.
class PeopleOsLogo extends StatelessWidget {
  const PeopleOsLogo({super.key, this.size = 40, this.light = false});

  final double size;

  /// Use white text (on dark backgrounds).
  final bool light;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Semantics(
      label: 'PeopleOS',
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          PeopleOsMark(size: size),
          SizedBox(width: size * 0.25),
          ExcludeSemantics(
            child: Text(
              'PeopleOS',
              style: Theme.of(context).textTheme.headlineMedium!.copyWith(
                fontSize: size * 0.55,
                height: 1.1,
                color: light ? Colors.white : c.textPrimary,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
