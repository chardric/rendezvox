<?php

declare(strict_types=1);

/**
 * Mood-based programming engine.
 *
 * Provides dayparting curves (target energy/valence by hour of day)
 * and weather-reactive bias adjustment.
 *
 * Used by NextTrackHandler to score candidate songs for mood fitness.
 */
class MoodEngine
{
    /**
     * Dayparting curves: target energy and valence by hour (0-23).
     *
     * Design philosophy:
     *   6-9 AM  — Gradual wake-up: moderate energy, positive valence
     *   9-12 PM — Peak morning: high energy, high valence
     *   12-3 PM — Post-lunch dip: moderate energy, calm
     *   3-6 PM  — Drive-time: high energy peak
     *   6-10 PM — Evening wind-down: gradual decline
     *   10 PM-6 AM — Night/overnight: low energy, mellow
     */
    private const DAYPART_CURVES = [
        //          [energy, valence]
        0  => [0.25, 0.30],  // midnight
        1  => [0.20, 0.25],
        2  => [0.18, 0.22],
        3  => [0.15, 0.20],  // lowest point
        4  => [0.18, 0.25],
        5  => [0.25, 0.35],  // pre-dawn rise
        6  => [0.35, 0.45],  // wake-up
        7  => [0.45, 0.55],
        8  => [0.55, 0.60],
        9  => [0.65, 0.70],  // morning peak
        10 => [0.70, 0.72],
        11 => [0.68, 0.68],
        12 => [0.55, 0.55],  // lunch dip
        13 => [0.50, 0.50],
        14 => [0.52, 0.52],
        15 => [0.60, 0.60],  // drive-time build
        16 => [0.70, 0.65],
        17 => [0.75, 0.70],  // drive-time peak
        18 => [0.65, 0.60],  // evening start
        19 => [0.55, 0.55],
        20 => [0.45, 0.48],
        21 => [0.38, 0.42],  // wind-down
        22 => [0.30, 0.35],
        23 => [0.28, 0.32],
    ];

    /**
     * Get the target mood for the current time of day.
     *
     * Interpolates between hour boundaries for smooth transitions.
     *
     * @return array{energy_target: float, valence_target: float, hour: int}
     */
    public static function getTargetMood(string $tz = 'UTC'): array
    {
        $now = new \DateTime('now', new \DateTimeZone($tz));
        $hour   = (int) $now->format('G');
        $minute = (int) $now->format('i');

        // Interpolate between current and next hour
        $nextHour = ($hour + 1) % 24;
        $fraction = $minute / 60.0;

        $current = self::DAYPART_CURVES[$hour];
        $next    = self::DAYPART_CURVES[$nextHour];

        $energy  = $current[0] + ($next[0] - $current[0]) * $fraction;
        $valence = $current[1] + ($next[1] - $current[1]) * $fraction;

        return [
            'energy_target'  => round($energy, 3),
            'valence_target' => round($valence, 3),
            'hour'           => $hour,
        ];
    }

    /**
     * Apply weather bias to mood targets.
     *
     * Weather conditions nudge the target mood:
     *   Rain/drizzle    → energy -0.10, valence -0.15 (mellow, introspective)
     *   Thunderstorm    → energy -0.20, valence -0.10 (cozy)
     *   Clear/sunny     → energy +0.05, valence +0.10 (upbeat)
     *   Cloudy/overcast → energy -0.05, valence -0.05 (neutral lean)
     *   Snow            → energy -0.10, valence +0.05 (calm but whimsical)
     *
     * @param array $target  Result from getTargetMood()
     * @param array $weather Weather data with 'weathercode' key (WMO codes)
     * @return array Modified target with weather bias applied
     */
    public static function applyWeatherBias(array $target, array $weather): array
    {
        $code = (int) ($weather['weathercode'] ?? $weather['weather_code'] ?? -1);

        $energyBias  = 0.0;
        $valenceBias = 0.0;

        if ($code <= 1) {
            // Clear sky / mainly clear
            $energyBias  = 0.05;
            $valenceBias = 0.10;
        } elseif ($code <= 3) {
            // Partly cloudy / overcast
            $energyBias  = -0.05;
            $valenceBias = -0.05;
        } elseif ($code >= 51 && $code <= 67) {
            // Drizzle / rain
            $energyBias  = -0.10;
            $valenceBias = -0.15;
        } elseif ($code >= 71 && $code <= 77) {
            // Snow
            $energyBias  = -0.10;
            $valenceBias = 0.05;
        } elseif ($code >= 80 && $code <= 82) {
            // Rain showers
            $energyBias  = -0.10;
            $valenceBias = -0.12;
        } elseif ($code >= 95) {
            // Thunderstorm
            $energyBias  = -0.20;
            $valenceBias = -0.10;
        }

        $target['energy_target']  = max(0.05, min(1.0, $target['energy_target'] + $energyBias));
        $target['valence_target'] = max(0.05, min(1.0, $target['valence_target'] + $valenceBias));
        $target['weather_code']   = $code;
        $target['weather_bias']   = ['energy' => $energyBias, 'valence' => $valenceBias];

        return $target;
    }

    /**
     * Score a song's fitness for the current mood target.
     *
     * Lower score = better fit. Uses weighted Euclidean distance
     * in (energy, valence) space.
     *
     * @param float $songEnergy  Song's energy (0-1)
     * @param float $songValence Song's valence (0-1)
     * @param float $targetEnergy  Target energy
     * @param float $targetValence Target valence
     * @return float Distance score (0.0 = perfect, ~1.4 = worst)
     */
    public static function moodDistance(
        float $songEnergy,
        float $songValence,
        float $targetEnergy,
        float $targetValence
    ): float {
        $dE = $songEnergy - $targetEnergy;
        $dV = $songValence - $targetValence;
        return sqrt($dE * $dE + $dV * $dV);
    }

    /**
     * Convert mood distance to a weight multiplier.
     *
     * Songs close to target get a boost (up to 2.5x),
     * songs far from target get a penalty (down to 0.3x).
     *
     * @param float $distance From moodDistance()
     * @return float Multiplier to apply to effective_weight
     */
    public static function distanceToMultiplier(float $distance): float
    {
        // Gaussian-like falloff: e^(-distance^2 / 0.1)
        // distance 0.0 → 2.5x, distance 0.3 → ~1.0x, distance 0.7 → ~0.3x
        $raw = exp(-($distance * $distance) / 0.1);
        return max(0.3, min(2.5, 0.3 + 2.2 * $raw));
    }
}
