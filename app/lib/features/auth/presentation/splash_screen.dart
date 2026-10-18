import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:peopleos/core/theme/people_colors.dart';
import 'package:peopleos/core/widgets/peopleos_logo.dart';
import 'package:peopleos/features/auth/presentation/auth_controller.dart';
import 'package:peopleos/l10n/l10n.dart';

/// Splash: restores the session, then the router redirects to Login or Home.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> {
  @override
  void initState() {
    super.initState();
    unawaited(_start());
  }

  Future<void> _start() async {
    final minimum = Future<void>.delayed(const Duration(milliseconds: 900));
    await ref.read(authControllerProvider.notifier).bootstrap();
    await minimum;
  }

  @override
  Widget build(BuildContext context) {
    final c = context.colors;
    return Scaffold(
      backgroundColor: c.splashTop,
      body: Stack(
        fit: StackFit.expand,
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [c.splashTop, c.splashBottom],
              ),
            ),
          ),
          const Positioned.fill(child: _Waves()),
          Center(
            child: Semantics(
              label: context.l10n.authSplashSemantics,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const PeopleOsMark(size: 84),
                  const SizedBox(height: 20),
                  Text(
                    'PeopleOS',
                    style: Theme.of(context).textTheme.displayMedium!
                        .copyWith(color: Colors.white, fontSize: 36),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    context.l10n.authTagline,
                    style: Theme.of(context).textTheme.bodyMedium!
                        .copyWith(color: Colors.white.withValues(alpha: 0.78)),
                  ),
                ],
              ),
            ),
          ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 36,
            child: Center(
              child: SizedBox(
                width: 22,
                height: 22,
                child: CircularProgressIndicator(
                  strokeWidth: 2.2,
                  color: Colors.white.withValues(alpha: 0.7),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Waves extends StatelessWidget {
  const _Waves();

  @override
  Widget build(BuildContext context) =>
      const ExcludeSemantics(child: CustomPaint(painter: _WavePainter()));
}

class _WavePainter extends CustomPainter {
  const _WavePainter();

  @override
  void paint(Canvas canvas, Size s) {
    void wave(double baseline, double amp, double phase, Color color) {
      final path = Path()..moveTo(0, s.height);
      path.lineTo(0, s.height * baseline);
      for (double x = 0; x <= s.width; x += 4) {
        final y =
            s.height * baseline +
            amp * s.height * math.sin(x / s.width * 6.2832 + phase);
        path.lineTo(x, y);
      }
      path
        ..lineTo(s.width, s.height)
        ..close();
      canvas.drawPath(path, Paint()..color = color);
    }

    wave(0.78, 0.035, 0.6, const Color(0x1F60A5FA));
    wave(0.86, 0.03, 2.4, const Color(0x2660A5FA));
  }

  @override
  bool shouldRepaint(_WavePainter old) => false;
}
