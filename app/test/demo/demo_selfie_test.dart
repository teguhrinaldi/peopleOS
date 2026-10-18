import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:peopleos/features/demo/demo_services.dart';

import '../helpers/fakes.dart';

void main() {
  testWidgets('desktop/web demo selfie is a decodable generated portrait', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.windows;
    try {
      final camera = FakeSelfieService();
      final photo = await DemoSelfieService(camera).capture();

      expect(camera.calls, 0, reason: 'no camera on desktop/web');
      expect(photo, isNotNull);
      expect(photo!.bytes.length, 54 + 240 * 240 * 3);
      await tester.pumpWidget(
        MaterialApp(home: Image.memory(photo.bytes, width: 80, height: 80)),
      );
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 200)),
      );
      await tester.pump();
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
    } finally {
      debugDefaultTargetPlatformOverride = null;
    }
  });

  test('phones use the real camera', () async {
    debugDefaultTargetPlatformOverride = TargetPlatform.android;
    try {
      final camera = FakeSelfieService();
      await DemoSelfieService(camera).capture();
      expect(camera.calls, 1);
    } finally {
      debugDefaultTargetPlatformOverride = null;
    }
  });
}
