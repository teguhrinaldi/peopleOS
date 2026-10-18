import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/theme/people_tokens.dart';
import 'package:peopleos/features/attendance/domain/attendance_models.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Map-style card without a map SDK: a stylised street grid with the work
/// site's geofence circle and the employee's position drawn to scale.
class LocationMapCard extends StatelessWidget {
  const LocationMapCard({
    super.key,
    required this.site,
    this.userLat,
    this.userLng,
    this.loading = false,
    this.height = 170,
  });

  final WorkSite? site;
  final double? userLat;
  final double? userLng;
  final bool loading;
  final double height;

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    final s = site;
    final label = loading
        ? context.l10n.checkMapLocating
        : s == null || userLat == null
        ? context.l10n.checkMapGeneric
        : context.l10n.checkMapRelative(s.name);
    return Semantics(
      label: label,
      image: true,
      child: ClipRRect(
        borderRadius: PeopleRadii.cardRadius,
        child: Container(
          height: height,
          decoration: BoxDecoration(
            color: c.surfaceMuted,
            border: Border.all(color: c.border),
            borderRadius: PeopleRadii.cardRadius,
          ),
          child: CustomPaint(
            painter: _MapPainter(
              streets: c.borderStrong,
              blocks: c.neutralSoft,
              park: c.success.withValues(alpha: 0.16),
              accent: c.primary,
              userColor: c.success,
              surface: c.surface,
              site: s,
              userLat: userLat,
              userLng: userLng,
              loading: loading,
            ),
          ),
        ),
      ),
    );
  }
}

class _MapPainter extends CustomPainter {
  _MapPainter({
    required this.streets,
    required this.blocks,
    required this.park,
    required this.accent,
    required this.userColor,
    required this.surface,
    required this.site,
    required this.userLat,
    required this.userLng,
    required this.loading,
  });

  final Color streets;
  final Color blocks;
  final Color park;
  final Color accent;
  final Color userColor;
  final Color surface;
  final WorkSite? site;
  final double? userLat;
  final double? userLng;
  final bool loading;

  @override
  void paint(Canvas canvas, Size size) {
    _paintStreets(canvas, size);
    final s = site;
    if (s == null || loading) return;

    // Metres east/north of the site for the user marker.
    double dx = 0;
    double dy = 0;
    if (userLat != null && userLng != null) {
      dy = (userLat! - s.latitude) * 111320;
      dx =
          (userLng! - s.longitude) *
          111320 *
          math.cos(s.latitude * math.pi / 180);
    }
    final reach = math.max(
      s.radiusMeters * 1.35,
      math.sqrt(dx * dx + dy * dy) * 1.25,
    );
    final scale = (math.min(size.width, size.height) / 2 - 14) / reach;
    final center = Offset(size.width / 2, size.height / 2);

    canvas.drawCircle(
      center,
      s.radiusMeters * scale,
      Paint()..color = accent.withValues(alpha: 0.14),
    );
    canvas.drawCircle(
      center,
      s.radiusMeters * scale,
      Paint()
        ..color = accent.withValues(alpha: 0.6)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.6,
    );
    _pin(canvas, center, accent);

    if (userLat != null) {
      final user = center + Offset(dx * scale, -dy * scale);
      canvas.drawCircle(
        user,
        11,
        Paint()..color = userColor.withValues(alpha: 0.25),
      );
      canvas.drawCircle(user, 6.5, Paint()..color = surface);
      canvas.drawCircle(user, 4.5, Paint()..color = userColor);
    }
  }

  void _paintStreets(Canvas canvas, Size size) {
    canvas.drawRect(
      Rect.fromLTWH(
        size.width * 0.06,
        size.height * 0.1,
        size.width * 0.2,
        size.height * 0.28,
      ),
      Paint()..color = park,
    );
    canvas.drawRect(
      Rect.fromLTWH(
        size.width * 0.72,
        size.height * 0.58,
        size.width * 0.22,
        size.height * 0.3,
      ),
      Paint()..color = park,
    );
    final major = Paint()
      ..color = streets
      ..strokeWidth = 7
      ..strokeCap = StrokeCap.round;
    final minor = Paint()
      ..color = streets.withValues(alpha: 0.7)
      ..strokeWidth = 3;
    canvas.drawLine(
      Offset(0, size.height * 0.62),
      Offset(size.width, size.height * 0.34),
      major,
    );
    canvas.drawLine(
      Offset(size.width * 0.3, 0),
      Offset(size.width * 0.52, size.height),
      major,
    );
    for (final f in [0.12, 0.42, 0.85]) {
      canvas.drawLine(
        Offset(size.width * f, 0),
        Offset(size.width * (f + 0.06), size.height),
        minor,
      );
    }
    for (final f in [0.2, 0.82]) {
      canvas.drawLine(
        Offset(0, size.height * f),
        Offset(size.width, size.height * (f - 0.06)),
        minor,
      );
    }
  }

  void _pin(Canvas canvas, Offset c, Color color) {
    final path = Path()
      ..moveTo(c.dx, c.dy + 4)
      ..cubicTo(c.dx - 16, c.dy - 10, c.dx - 12, c.dy - 30, c.dx, c.dy - 30)
      ..cubicTo(c.dx + 12, c.dy - 30, c.dx + 16, c.dy - 10, c.dx, c.dy + 4)
      ..close();
    canvas.drawShadow(path, Colors.black, 3, true);
    canvas.drawPath(path, Paint()..color = color);
    canvas.drawCircle(
      Offset(c.dx, c.dy - 17),
      5,
      Paint()..color = Colors.white,
    );
  }

  @override
  bool shouldRepaint(_MapPainter old) =>
      old.userLat != userLat ||
      old.userLng != userLng ||
      old.site != site ||
      old.loading != loading ||
      old.streets != streets;
}
