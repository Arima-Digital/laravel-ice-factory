<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Warehouse;

/**
 * Orders the stores on a delivery plan and reports how far the route runs.
 *
 * The BRD asks for "auto-sort by optimal route (nearest first)" with a total
 * distance and duration, without naming a routing provider. This walks out from
 * the warehouse and repeatedly jumps to the closest unvisited store, which is
 * what "nearest first" describes, and costs nothing to run.
 *
 * Distances are straight-line, not road distances. A road network is not
 * available to this service, so every leg is scaled by ROAD_WINDING_FACTOR to
 * approximate the extra distance a real road covers. Good enough to order a
 * handful of stops and to tell the driver how far they are going; not a
 * substitute for a directions API when it matters.
 *
 * A store or warehouse without coordinates cannot be measured. Rather than
 * silently dropping it, those are kept in the caller's original order and
 * reported through hasCoordinates() so the caller can say the route is partial.
 */
class RoutePlannerService
{
    /**
     * Straight lines understate real distance; urban roads wind.
     */
    public const ROAD_WINDING_FACTOR = 1.35;

    /**
     * Mean radius of the earth in kilometres.
     */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * Average delivery speed including city traffic, in km/h.
     */
    public const AVERAGE_SPEED_KMH = 25.0;

    /**
     * Time spent at each store for the unload and the settlement talk.
     */
    public const SERVICE_MINUTES_PER_STOP = 15.0;

    /**
     * Order the given stores into a route starting from the warehouse.
     *
     * Stores without coordinates keep their relative input order and are
     * appended after the ones that could be ordered, so a plan is never built
     * on a guess about where an unlocatable store sits.
     *
     * @param  array<int>  $storeIds
     * @return array<int>
     */
    public function order(?Warehouse $warehouse, array $storeIds): array
    {
        $stores = Store::whereIn('id', $storeIds)->get()->keyBy('id');

        $ordered = [];
        $remaining = [];

        foreach ($storeIds as $storeId) {
            if (! isset($stores[$storeId])) {
                continue;
            }

            if ($this->hasCoordinates($stores[$storeId])) {
                $ordered[] = (int) $storeId;
            } else {
                $remaining[] = (int) $storeId;
            }
        }

        if (! $this->hasCoordinates($warehouse)) {
            // Without an origin there is no "nearest", so the plan the user
            // chose is the route.
            return array_values(array_unique(array_merge($ordered, $remaining)));
        }

        $current = $warehouse;
        $route = [];

        while ($ordered !== []) {
            $nearest = $ordered[0];
            $nearestDistance = null;

            foreach ($ordered as $storeId) {
                $distance = $this->straightLineKm(
                    $current,
                    $stores[$storeId]
                );

                if ($nearestDistance === null || $distance < $nearestDistance) {
                    $nearest = $storeId;
                    $nearestDistance = $distance;
                }
            }

            $route[] = $nearest;
            $current = $stores[$nearest];
            $ordered = array_values(array_diff($ordered, [$nearest]));
        }

        return array_merge($route, $remaining);
    }

    /**
     * Straight-line distance between two positioned models, in kilometres.
     */
    public function straightLineKm(mixed $from, mixed $to): float
    {
        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $to->longitude - (float) $from->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Approximate road distance between two positioned models, in kilometres.
     */
    public function roadKm(mixed $from, mixed $to): float
    {
        return $this->straightLineKm($from, $to) * self::ROAD_WINDING_FACTOR;
    }

    /**
     * Total road distance of a route, in kilometres, plus the time it takes.
     *
     * @param  array<int>  $storeIds
     * @return array{total_km: float, total_minutes: float, order: array<int>}
     */
    public function summarise(?Warehouse $warehouse, array $storeIds): array
    {
        $stores = Store::whereIn('id', $storeIds)->get()->keyBy('id');

        $totalKm = 0.0;
        $legs = 0;

        if ($this->hasCoordinates($warehouse)) {
            $current = $warehouse;

            foreach ($storeIds as $storeId) {
                if (! isset($stores[$storeId]) || ! $this->hasCoordinates($stores[$storeId])) {
                    continue;
                }

                $totalKm += $this->roadKm($current, $stores[$storeId]);
                $current = $stores[$storeId];
                $legs++;
            }
        }

        // Driving plus the time spent at each stop.
        $drivingMinutes = self::AVERAGE_SPEED_KMH > 0 ? ($totalKm / self::AVERAGE_SPEED_KMH) * 60 : 0.0;
        $totalMinutes = $drivingMinutes + (count($storeIds) * self::SERVICE_MINUTES_PER_STOP);

        return [
            'total_km' => round($totalKm, 1),
            'total_minutes' => (int) round($totalMinutes),
            'order' => array_values($storeIds),
        ];
    }

    /**
     * Whether a point can be placed on a map.
     *
     * Both halves have to be present: a latitude with no longitude points at
     * null island, which is worse than having no position at all.
     */
    private function hasCoordinates(mixed $model): bool
    {
        if ($model === null) {
            return false;
        }

        return $model->latitude !== null && $model->longitude !== null;
    }
}
