import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:peopleos/core/network/connectivity_service.dart';
import 'package:peopleos/core/platform/location_service.dart';
import 'package:peopleos/core/platform/selfie_service.dart';
import 'package:peopleos/core/storage/app_settings.dart';
import 'package:peopleos/features/demo/demo_data.dart';

/// Fake GPS for Demo mode: a fix near the Head Office, or ~330 m away when
/// the evaluator switches "Simulate outside work area" on.
class DemoLocationService implements LocationService {
  DemoLocationService(this._settings);

  final AppSettings _settings;

  @override
  Future<LocationFix> currentFix() async {
    await Future<void>.delayed(const Duration(milliseconds: 600));
    final outside = _settings.demoOutsideArea;
    return LocationFix(
      latitude: DemoData.siteLat + (outside ? 0.003 : 0.0004),
      longitude: DemoData.siteLng + (outside ? 0.0004 : 0.0002),
      accuracyMeters: 12,
      capturedAt: DateTime.now(),
    );
  }

  @override
  Future<void> openAppSettings() async {}

  @override
  Future<void> openLocationSettings() async {}
}

/// Demo connectivity = real connectivity AND the "simulate offline" switch.
class DemoConnectivityService implements ConnectivityService {
  DemoConnectivityService(this._real, this._settings);

  final ConnectivityService _real;
  final AppSettings _settings;
  final _controller = StreamController<bool>.broadcast();

  /// Called by the settings screen after the switch changes.
  Future<void> notifyChanged() async => _controller.add(await isOnline);

  @override
  Future<bool> get isOnline async =>
      !_settings.demoOffline && await _real.isOnline;

  @override
  Stream<bool> get onlineChanges async* {
    yield* _merge(
      _controller.stream,
      _real.onlineChanges.asyncMap((_) => isOnline),
    );
  }

  Stream<bool> _merge(Stream<bool> a, Stream<bool> b) {
    final out = StreamController<bool>();
    final subs = [a.listen(out.add), b.listen(out.add)];
    out.onCancel = () {
      for (final s in subs) {
        unawaited(s.cancel());
      }
    };
    return out.stream.distinct();
  }
}

/// Demo selfie: real front camera on phones; a generated sample portrait on
/// web/desktop where there is no camera to use.
class DemoSelfieService implements SelfieService {
  DemoSelfieService(this._camera);

  final SelfieService _camera;

  bool get _hasCamera =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  @override
  Future<SelfiePhoto?> capture() async {
    if (_hasCamera) return _camera.capture();
    return SelfiePhoto(await _samplePortrait(), fileName: 'demo-selfie.bmp');
  }

  /// A small vector-style portrait as an uncompressed BMP: no canvas or
  /// image codec is needed, so it works on every platform including web.
  static Future<Uint8List> _samplePortrait() async {
    const size = 240;
    const rowBytes = size * 3;
    final data = ByteData(54 + rowBytes * size)
      ..setUint8(0, 0x42)
      ..setUint8(1, 0x4D)
      ..setUint32(2, 54 + rowBytes * size, Endian.little)
      ..setUint32(10, 54, Endian.little)
      ..setUint32(14, 40, Endian.little)
      ..setInt32(18, size, Endian.little)
      ..setInt32(22, size, Endian.little)
      ..setUint16(26, 1, Endian.little)
      ..setUint16(28, 24, Endian.little)
      ..setUint32(34, rowBytes * size, Endian.little);

    bool inCircle(double x, double y, double cx, double cy, double r) =>
        (x - cx) * (x - cx) + (y - cy) * (y - cy) <= r * r;

    var offset = 54;
    for (var row = size - 1; row >= 0; row--) {
      for (var col = 0; col < size; col++) {
        final x = col.toDouble();
        final y = row.toDouble();
        var rgb = const [0xCF, 0xE0, 0xF7];
        if (inCircle(x, y, 120, 105, 46) && y > 100) {
          rgb = const [0xE2, 0xB4, 0x8F];
        } else if (inCircle(x, y, 120, 92, 50) && y <= 100) {
          rgb = const [0x2B, 0x23, 0x20];
        } else if (inCircle(x, y, 120, 105, 46)) {
          rgb = const [0xE2, 0xB4, 0x8F];
        } else if (((x - 120) * (x - 120)) / (86 * 86) +
                ((y - 250) * (y - 250)) / (100 * 100) <=
            1) {
          rgb = const [0x1E, 0x3A, 0x6D];
        }
        data
          ..setUint8(offset++, rgb[2])
          ..setUint8(offset++, rgb[1])
          ..setUint8(offset++, rgb[0]);
      }
    }
    return data.buffer.asUint8List();
  }
}
