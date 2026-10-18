<?php

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Attendance\GeofenceService;
use PHPUnit\Framework\TestCase;

class GeofenceServiceTest extends TestCase
{
    private GeofenceService $geo;

    protected function setUp(): void
    {
        $this->geo = new GeofenceService;
    }

    public function test_identical_points_are_zero_meters_apart(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->geo->distanceMeters(-6.9218, 107.6071, -6.9218, 107.6071), 0.001);
    }

    public function test_one_millidegree_of_latitude_is_about_111_meters(): void
    {
        $this->assertEqualsWithDelta(111.2, $this->geo->distanceMeters(0.0, 100.0, 0.001, 100.0), 0.5);
    }

    public function test_known_city_distance_is_close_to_reality(): void
    {
        // Jakarta (-6.2088, 106.8456) <-> Bandung (-6.9175, 107.6191) is roughly 116 km.
        $km = $this->geo->distanceMeters(-6.2088, 106.8456, -6.9175, 107.6191) / 1000;
        $this->assertEqualsWithDelta(116.0, $km, 2.0);
    }

    public function test_inside_and_outside_the_radius(): void
    {
        $inside = $this->geo->evaluate(-6.9218, 107.6071, 150, -6.9224, 107.6071); // ~67 m
        $outside = $this->geo->evaluate(-6.9218, 107.6071, 150, -6.9240, 107.6071); // ~245 m

        $this->assertTrue($inside['inside']);
        $this->assertEqualsWithDelta(66.7, $inside['distance'], 1.0);
        $this->assertFalse($outside['inside']);
    }

    public function test_the_boundary_is_inclusive(): void
    {
        $distance = $this->geo->distanceMeters(-6.9218, 107.6071, -6.9228, 107.6071);
        $this->assertTrue($this->geo->evaluate(-6.9218, 107.6071, (int) ceil($distance), -6.9228, 107.6071)['inside']);
        $this->assertFalse($this->geo->evaluate(-6.9218, 107.6071, (int) floor($distance) - 1, -6.9228, 107.6071)['inside']);
    }

    public function test_it_works_across_the_antimeridian_and_the_equator(): void
    {
        $this->assertEqualsWithDelta(22.2, $this->geo->distanceMeters(0.0, 179.9999, 0.0, -179.9999), 0.5);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $this->expectException(ApiException::class);
        $this->geo->evaluate(-6.9, 107.6, 100, 91.0, 0.0);
    }
}
