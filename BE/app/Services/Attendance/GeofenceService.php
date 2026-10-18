<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;

/**
 * Server-side geofence maths. The client's opinion about being "inside" is never trusted.
 */
class GeofenceService
{
    private const EARTH_RADIUS_METERS = 6371008.8;

    /** Great-circle distance in meters (haversine). */
    public function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** @return array{distance: float, inside: bool} */
    public function evaluate(
        float $siteLat,
        float $siteLng,
        int $radiusMeters,
        float $lat,
        float $lng,
    ): array {
        $this->assertValidCoordinate($lat, $lng);
        $distance = $this->distanceMeters($siteLat, $siteLng, $lat, $lng);

        return ['distance' => round($distance, 2), 'inside' => $distance <= $radiusMeters];
    }

    public function assertValidCoordinate(float $lat, float $lng): void
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || is_nan($lat) || is_nan($lng)) {
            throw ApiException::make('INVALID_COORDINATES', 'The supplied coordinates are not valid.');
        }
    }
}
