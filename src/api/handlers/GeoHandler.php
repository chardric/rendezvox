<?php

declare(strict_types=1);

/**
 * Philippine geographic data API + Nominatim geocoding proxy.
 *
 * Endpoints:
 *   GET /admin/geo/provinces              → { provinces: [...] }
 *   GET /admin/geo/cities?province=X      → { cities: [{name, coords}, ...] }
 *   GET /admin/geo/barangays?province=X&city=Y → { barangays: [...], coords: [lat,lon] }
 *   GET /admin/geo/geocode?q=...          → { lat, lon, display_name }
 */
class GeoHandler
{
    private const DATA_FILE = __DIR__ . '/../../data/ph-geo.json';

    /** @var array|null Cached decoded geo data */
    private static ?array $geoData = null;

    private function loadData(): ?array
    {
        if (self::$geoData !== null) {
            return self::$geoData;
        }

        $path = self::DATA_FILE;
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        self::$geoData = json_decode($json, true);
        return self::$geoData;
    }

    /**
     * GET /admin/geo/provinces
     */
    public function provinces(): void
    {
        Auth::requireRole('super_admin');

        $data = $this->loadData();
        if ($data === null) {
            Response::error('Geographic data not available. Run: node tools/build-ph-geo.js', 500);
            return;
        }

        $provinces = array_keys($data);
        sort($provinces, SORT_STRING | SORT_FLAG_CASE);

        Response::json(['provinces' => $provinces]);
    }

    /**
     * GET /admin/geo/cities?province=X
     */
    public function cities(): void
    {
        Auth::requireRole('super_admin');

        $province = trim($_GET['province'] ?? '');
        if ($province === '') {
            Response::error('province parameter is required', 400);
            return;
        }

        $data = $this->loadData();
        if ($data === null) {
            Response::error('Geographic data not available', 500);
            return;
        }

        if (!isset($data[$province])) {
            Response::error('Province not found: ' . $province, 404);
            return;
        }

        $cities = [];
        foreach ($data[$province] as $name => $info) {
            $cities[] = [
                'name'   => $name,
                'coords' => $info['c'] ?? [0, 0],
            ];
        }

        usort($cities, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        Response::json(['cities' => $cities]);
    }

    /**
     * GET /admin/geo/barangays?province=X&city=Y
     */
    public function barangays(): void
    {
        Auth::requireRole('super_admin');

        $province = trim($_GET['province'] ?? '');
        $city     = trim($_GET['city'] ?? '');

        if ($province === '' || $city === '') {
            Response::error('province and city parameters are required', 400);
            return;
        }

        $data = $this->loadData();
        if ($data === null) {
            Response::error('Geographic data not available', 500);
            return;
        }

        if (!isset($data[$province][$city])) {
            Response::error('City not found: ' . $city . ' in ' . $province, 404);
            return;
        }

        $entry = $data[$province][$city];

        Response::json([
            'barangays' => $entry['b'] ?? [],
            'coords'    => $entry['c'] ?? [0, 0],
        ]);
    }

    /**
     * GET /admin/geo/geocode?q=...
     * Proxies to Nominatim to resolve a location string to lat/lon.
     */
    public function geocode(): void
    {
        Auth::requireRole('super_admin');

        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            Response::error('q parameter is required', 400);
            return;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'      => $q,
            'format' => 'json',
            'limit'  => 1,
            'countrycodes' => 'ph',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: iRadio/1.0\r\n",
                'timeout' => 5,
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) {
            Response::json(['lat' => null, 'lon' => null, 'display_name' => null]);
            return;
        }

        $results = json_decode($json, true);
        if (!is_array($results) || empty($results)) {
            Response::json(['lat' => null, 'lon' => null, 'display_name' => null]);
            return;
        }

        $hit = $results[0];
        Response::json([
            'lat'          => round((float) $hit['lat'], 4),
            'lon'          => round((float) $hit['lon'], 4),
            'display_name' => $hit['display_name'] ?? null,
        ]);
    }
}
