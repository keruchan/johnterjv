<?php
/**
 * Shared server-side validation for browser-drawn map geometry.
 *
 * Boundary polygons are drawn client-side with Leaflet and submitted as a
 * GeoJSON FeatureCollection string. The client is never trusted: this module
 * re-validates structure, geometry types, coordinate ranges, and size before
 * anything is stored, and normalizes the stored text via re-encoding.
 */

class GeoValidationException extends RuntimeException
{
}

/** Upper bound for stored boundary text; generous for hand-drawn polygons. */
const CERTREEFY_BOUNDARY_GEOJSON_MAX_BYTES = 512000;

/**
 * Validates a submitted boundary GeoJSON FeatureCollection.
 * Returns the normalized JSON string, or null when the input is blank.
 */
function geo_validate_boundary_geojson(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    if (strlen($raw) > CERTREEFY_BOUNDARY_GEOJSON_MAX_BYTES) {
        throw new GeoValidationException('The drawn boundary is too large to store.');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || ($decoded['type'] ?? '') !== 'FeatureCollection' || !is_array($decoded['features'] ?? null)) {
        throw new GeoValidationException('The drawn boundary is not a valid GeoJSON FeatureCollection.');
    }
    if ($decoded['features'] === []) {
        return null;
    }
    if (count($decoded['features']) > 50) {
        throw new GeoValidationException('A boundary may contain at most 50 shapes.');
    }

    $features = [];
    foreach ($decoded['features'] as $feature) {
        if (!is_array($feature) || ($feature['type'] ?? '') !== 'Feature' || !is_array($feature['geometry'] ?? null)) {
            throw new GeoValidationException('The drawn boundary contains an invalid feature.');
        }
        $geometry = $feature['geometry'];
        $geometryType = (string) ($geometry['type'] ?? '');
        if (!in_array($geometryType, ['Polygon', 'MultiPolygon'], true)) {
            throw new GeoValidationException('Boundaries may only contain polygon shapes.');
        }
        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates)) {
            throw new GeoValidationException('The drawn boundary has invalid coordinates.');
        }
        $polygons = $geometryType === 'Polygon' ? [$coordinates] : $coordinates;
        foreach ($polygons as $rings) {
            if (!is_array($rings) || $rings === []) {
                throw new GeoValidationException('The drawn boundary has invalid coordinates.');
            }
            foreach ($rings as $ring) {
                if (!is_array($ring) || count($ring) < 4) {
                    throw new GeoValidationException('Each boundary shape needs at least three points.');
                }
                foreach ($ring as $position) {
                    if (!is_array($position) || count($position) < 2
                        || !is_numeric($position[0]) || !is_numeric($position[1])) {
                        throw new GeoValidationException('The drawn boundary has invalid coordinates.');
                    }
                    $lng = (float) $position[0];
                    $lat = (float) $position[1];
                    if ($lng < -180 || $lng > 180 || $lat < -90 || $lat > 90) {
                        throw new GeoValidationException('The drawn boundary has coordinates outside valid ranges.');
                    }
                }
            }
        }
        // Store only what the map needs; client-supplied properties are dropped.
        $features[] = [
            'type' => 'Feature',
            'properties' => new stdClass(),
            'geometry' => $geometry,
        ];
    }

    $normalized = json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_SLASHES);
    if ($normalized === false) {
        throw new GeoValidationException('The drawn boundary could not be stored.');
    }

    return $normalized;
}

/**
 * Bounding-box center [lat, lng] of a validated boundary GeoJSON string.
 * Used to derive a map center when none was supplied explicitly.
 */
function geo_boundary_center(?string $boundaryGeoJson): ?array
{
    if ($boundaryGeoJson === null || trim($boundaryGeoJson) === '') {
        return null;
    }
    $decoded = json_decode($boundaryGeoJson, true);
    if (!is_array($decoded)) {
        return null;
    }
    $minLat = 90.0;
    $maxLat = -90.0;
    $minLng = 180.0;
    $maxLng = -180.0;
    $found = false;
    foreach (($decoded['features'] ?? []) as $feature) {
        $geometry = $feature['geometry'] ?? [];
        $polygons = ($geometry['type'] ?? '') === 'Polygon'
            ? [$geometry['coordinates'] ?? []]
            : ($geometry['coordinates'] ?? []);
        foreach ($polygons as $rings) {
            foreach ((array) $rings as $ring) {
                foreach ((array) $ring as $position) {
                    if (!is_array($position) || count($position) < 2) {
                        continue;
                    }
                    $found = true;
                    $minLng = min($minLng, (float) $position[0]);
                    $maxLng = max($maxLng, (float) $position[0]);
                    $minLat = min($minLat, (float) $position[1]);
                    $maxLat = max($maxLat, (float) $position[1]);
                }
            }
        }
    }

    return $found ? [($minLat + $maxLat) / 2, ($minLng + $maxLng) / 2] : null;
}

/**
 * Validates an optional latitude/longitude pair submitted as strings.
 * Returns ['lat' => string, 'lng' => string] (7-decimal normalized) or null
 * when both are blank; throws when only one is present or a value is invalid.
 */
function geo_validate_optional_point(?string $latitude, ?string $longitude, string $subject = 'location'): ?array
{
    $latitude = trim((string) $latitude);
    $longitude = trim((string) $longitude);
    if ($latitude === '' && $longitude === '') {
        return null;
    }
    if ($latitude === '' || $longitude === '') {
        throw new GeoValidationException('Provide both latitude and longitude for the ' . $subject . ', or leave both blank.');
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        throw new GeoValidationException('The ' . $subject . ' coordinates must be numeric.');
    }
    $latValue = (float) $latitude;
    $lngValue = (float) $longitude;
    if ($latValue < -90 || $latValue > 90 || $lngValue < -180 || $lngValue > 180) {
        throw new GeoValidationException('The ' . $subject . ' coordinates are outside valid ranges.');
    }

    return [
        'lat' => number_format($latValue, 7, '.', ''),
        'lng' => number_format($lngValue, 7, '.', ''),
    ];
}
