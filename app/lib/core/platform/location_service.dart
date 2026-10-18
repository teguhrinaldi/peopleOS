import 'package:geolocator/geolocator.dart';

class LocationFix {
  const LocationFix({
    required this.latitude,
    required this.longitude,
    required this.accuracyMeters,
    required this.capturedAt,
    this.isMocked = false,
  });

  final double latitude;
  final double longitude;
  final double accuracyMeters;
  final DateTime capturedAt;
  final bool isMocked;
}

enum LocationProblem {
  /// Permission denied this time; can ask again.
  denied,

  /// Permission permanently denied; only system settings can fix it.
  deniedForever,

  /// Device location services are switched off.
  serviceDisabled,

  /// No fix could be obtained in time.
  unavailable,
}

class LocationException implements Exception {
  const LocationException(this.problem);
  final LocationProblem problem;

  @override
  String toString() => 'LocationException($problem)';
}

/// Foreground, one-shot location. The app never tracks in the background:
/// this is only called when the user starts a check-in or check-out.
abstract class LocationService {
  /// Requests permission if needed and returns a single high-accuracy fix.
  /// Throws [LocationException].
  Future<LocationFix> currentFix();

  Future<void> openAppSettings();
  Future<void> openLocationSettings();
}

class GeolocatorLocationService implements LocationService {
  const GeolocatorLocationService();

  @override
  Future<LocationFix> currentFix() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const LocationException(LocationProblem.serviceDisabled);
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.deniedForever) {
      throw const LocationException(LocationProblem.deniedForever);
    }
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.unableToDetermine) {
      throw const LocationException(LocationProblem.denied);
    }
    try {
      final p = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.best,
          timeLimit: Duration(seconds: 20),
        ),
      );
      return LocationFix(
        latitude: p.latitude,
        longitude: p.longitude,
        accuracyMeters: p.accuracy,
        capturedAt: p.timestamp,
        isMocked: p.isMocked,
      );
    } catch (_) {
      throw const LocationException(LocationProblem.unavailable);
    }
  }

  @override
  Future<void> openAppSettings() async {
    await Geolocator.openAppSettings();
  }

  @override
  Future<void> openLocationSettings() async {
    await Geolocator.openLocationSettings();
  }
}
