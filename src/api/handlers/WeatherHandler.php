<?php

declare(strict_types=1);

/**
 * GET /api/weather
 *
 * Public endpoint returning current weather data.
 * Reads weather_latitude / weather_longitude from the settings table.
 * Falls back to server timezone geocoding if not configured.
 * Uses Open-Meteo for weather, Nominatim for reverse geocoding.
 * Caches per location grid for 30 minutes.
 */
class WeatherHandler
{
    private const CACHE_DIR = '/tmp';
    private const CACHE_TTL = 1800; // 30 minutes

    /** WMO Weather interpretation codes -> description + icon */
    private const WMO_CODES = [
        0  => ['Clear sky',            'sun'],
        1  => ['Mainly clear',         'sun'],
        2  => ['Partly cloudy',        'cloud-sun'],
        3  => ['Overcast',             'cloud'],
        45 => ['Foggy',                'fog'],
        48 => ['Depositing rime fog',  'fog'],
        51 => ['Light drizzle',        'drizzle'],
        53 => ['Moderate drizzle',     'drizzle'],
        55 => ['Dense drizzle',        'drizzle'],
        56 => ['Light freezing drizzle','drizzle'],
        57 => ['Dense freezing drizzle','drizzle'],
        61 => ['Slight rain',          'rain'],
        63 => ['Moderate rain',        'rain'],
        65 => ['Heavy rain',           'rain'],
        66 => ['Light freezing rain',  'rain'],
        67 => ['Heavy freezing rain',  'rain'],
        71 => ['Slight snow',          'snow'],
        73 => ['Moderate snow',        'snow'],
        75 => ['Heavy snow',           'snow'],
        77 => ['Snow grains',          'snow'],
        80 => ['Slight rain showers',  'rain'],
        81 => ['Moderate rain showers','rain'],
        82 => ['Violent rain showers', 'rain'],
        85 => ['Slight snow showers',  'snow'],
        86 => ['Heavy snow showers',   'snow'],
        95 => ['Thunderstorm',         'storm'],
        96 => ['Thunderstorm with hail','storm'],
        99 => ['Thunderstorm with heavy hail', 'storm'],
    ];

    public function handle(): void
    {
        // Resolve coordinates from settings DB, or fall back to timezone
        $coords = $this->coordsFromSettings();
        if ($coords === null) {
            $coords = $this->coordsFromTimezone();
        }
        if ($coords === null) {
            Response::json(['error' => 'Could not resolve location'], 404);
            return;
        }
        $lat = $coords['lat'];
        $lon = $coords['lon'];

        // Check cache for this location grid
        $cacheFile = self::CACHE_DIR . '/rendezvox_weather_' . $lat . '_' . $lon . '.json';
        if (file_exists($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if ($cached && isset($cached['_cached_at']) && (time() - $cached['_cached_at']) < self::CACHE_TTL) {
                unset($cached['_cached_at']);
                Response::json($cached);
                return;
            }
        }

        // Fetch current weather from Open-Meteo
        $wxUrl = 'https://api.open-meteo.com/v1/forecast?'
            . http_build_query([
                'latitude'  => $lat,
                'longitude' => $lon,
                'current'   => 'temperature_2m,relative_humidity_2m,wind_speed_10m,weather_code',
                'timezone'  => 'auto',
            ]);

        $wxCtx = stream_context_create(['http' => ['timeout' => 5]]);
        $wxJson = @file_get_contents($wxUrl, false, $wxCtx);
        if ($wxJson === false) {
            Response::json(['error' => 'Weather service unavailable'], 503);
            return;
        }

        $wx = json_decode($wxJson, true);
        if (!$wx || !isset($wx['current'])) {
            Response::json(['error' => 'Invalid weather response'], 502);
            return;
        }

        // Reverse geocode to get town/municipality name
        $locationName = $this->reverseGeocode($lat, $lon);

        $current = $wx['current'];
        $code    = (int) ($current['weather_code'] ?? 0);
        $wmo     = self::WMO_CODES[$code] ?? ['Unknown', 'cloud'];

        $result = [
            'temperature' => $current['temperature_2m'] ?? null,
            'unit'        => $wx['current_units']['temperature_2m'] ?? '°C',
            'description' => $wmo[0],
            'icon'        => $wmo[1],
            'humidity'    => $current['relative_humidity_2m'] ?? null,
            'wind_speed'  => $current['wind_speed_10m'] ?? null,
            'wind_unit'   => $wx['current_units']['wind_speed_10m'] ?? 'km/h',
            'location'    => $locationName,
        ];

        // Cache the result
        $toCache = $result;
        $toCache['_cached_at'] = time();
        @file_put_contents($cacheFile, json_encode($toCache));

        Response::json($result);
    }

    /**
     * Reverse geocode lat/lon to a descriptive location string
     * (e.g. "Progressive, Gonzaga, Cagayan") using OpenStreetMap Nominatim.
     */
    private function reverseGeocode(float $lat, float $lon): string
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?'
            . http_build_query([
                'lat'            => $lat,
                'lon'            => $lon,
                'format'         => 'json',
                'zoom'           => 18, // max detail (barangay/quarter level)
                'addressdetails' => 1,
            ]);

        $ctx = stream_context_create([
            'http' => [
                'header'  => "User-Agent: RendezVox/1.0\r\n",
                'timeout' => 5,
            ],
        ]);

        $json = @file_get_contents($url, false, $ctx);
        if ($json === false) {
            return $this->fallbackLocationName();
        }

        $data = json_decode($json, true);
        if (!$data || !isset($data['address'])) {
            return $this->fallbackLocationName();
        }

        $addr = $data['address'];

        // Build location from most specific to least:
        // barangay/quarter, town/municipality/city, province/state
        $parts = [];

        // Barangay / quarter / neighbourhood / village
        $local = $addr['quarter'] ?? $addr['neighbourhood'] ?? $addr['suburb'] ?? null;
        if ($local !== null) {
            $parts[] = $local;
        }

        // Town / municipality / city / village
        $town = $addr['town'] ?? $addr['municipality'] ?? $addr['city'] ?? $addr['village'] ?? null;
        if ($town !== null) {
            $parts[] = $town;
        }

        // Province / state
        $province = $addr['state'] ?? $addr['county'] ?? null;
        if ($province !== null) {
            $parts[] = $province;
        }

        if (empty($parts)) {
            return $data['display_name'] ?? $this->fallbackLocationName();
        }

        return implode(', ', $parts);
    }

    /**
     * Fallback location name from server timezone.
     */
    private function fallbackLocationName(): string
    {
        $tz = date_default_timezone_get() ?: 'UTC';
        $parts = explode('/', $tz);
        return str_replace('_', ' ', end($parts));
    }

    /**
     * Read weather coordinates from the settings table.
     */
    private function coordsFromSettings(): ?array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT key, value FROM settings WHERE key IN ('weather_latitude', 'weather_longitude')"
        );
        $stmt->execute();

        $vals = [];
        while ($row = $stmt->fetch()) {
            $vals[$row['key']] = $row['value'];
        }

        $lat = isset($vals['weather_latitude'])  ? (float) $vals['weather_latitude']  : 0.0;
        $lon = isset($vals['weather_longitude']) ? (float) $vals['weather_longitude'] : 0.0;

        if ($lat == 0.0 && $lon == 0.0) {
            return null;
        }

        return [
            'lat' => round($lat, 4),
            'lon' => round($lon, 4),
        ];
    }

    /**
     * Derive coordinates from server timezone using Open-Meteo geocoding.
     */
    private function coordsFromTimezone(): ?array
    {
        $city = $this->fallbackLocationName();

        $geoUrl = 'https://geocoding-api.open-meteo.com/v1/search?'
            . http_build_query(['name' => $city, 'count' => 1, 'format' => 'json']);

        $geoCtx = stream_context_create(['http' => ['timeout' => 5]]);
        $geoJson = @file_get_contents($geoUrl, false, $geoCtx);
        if ($geoJson === false) {
            return null;
        }

        $geo = json_decode($geoJson, true);
        if (!$geo || empty($geo['results'])) {
            return null;
        }

        return [
            'lat' => round((float) $geo['results'][0]['latitude'], 2),
            'lon' => round((float) $geo['results'][0]['longitude'], 2),
        ];
    }
}
