<?php

declare(strict_types=1);

/**
 * Crossfade Intelligence — determines optimal crossfade duration
 * for a track pair based on ending/intro energy characteristics.
 *
 * Rules:
 *   fade ending + low intro    → long crossfade (4-5s) — smooth blend
 *   fade ending + high intro   → medium crossfade (2-3s) — let new track punch in
 *   hard ending + any          → short gap (0.5-1s) — respect the hard stop
 *   silence ending             → no crossfade (cue_cut handles it)
 *   high energy contrast       → shorter crossfade — avoid muddy mix
 */
class CrossfadeAnalyzer
{
    /**
     * Get optimal transition parameters for the next track.
     *
     * @param array|null $outgoing Outgoing song data (ending_type, ending_energy, energy)
     * @param array|null $incoming Incoming song data (intro_energy, energy)
     * @param int        $defaultMs Default crossfade from settings
     * @return array{crossfade_ms: int}
     */
    public static function getTransitionParams(
        ?array $outgoing,
        ?array $incoming,
        int $defaultMs = 3000
    ): array {
        // If no mood data, use default
        if (!$outgoing || !isset($outgoing['ending_type'])) {
            return ['crossfade_ms' => $defaultMs];
        }

        $endingType   = $outgoing['ending_type'] ?? 'fade';
        $endingEnergy = (float) ($outgoing['ending_energy'] ?? 0.5);
        $introEnergy  = $incoming ? (float) ($incoming['intro_energy'] ?? 0.5) : 0.5;
        $energyDiff   = abs($endingEnergy - $introEnergy);

        $crossfadeMs = $defaultMs;

        switch ($endingType) {
            case 'silence':
                // Cue_cut trims silence. Minimal crossfade.
                $crossfadeMs = 500;
                break;

            case 'hard':
                // Respect the hard stop. Brief pause then new track.
                $crossfadeMs = max(500, min(1500, $defaultMs));
                // If high energy contrast, even shorter
                if ($energyDiff > 0.3) {
                    $crossfadeMs = 500;
                }
                break;

            case 'fade':
            default:
                if ($introEnergy < 0.3) {
                    // Low intro → long blend (both tracks quiet, smooth merge)
                    $crossfadeMs = min(5000, (int) ($defaultMs * 1.5));
                } elseif ($introEnergy > 0.6) {
                    // High intro → medium (let it punch in)
                    $crossfadeMs = max(1500, (int) ($defaultMs * 0.8));
                } else {
                    $crossfadeMs = $defaultMs;
                }

                // High energy contrast → shorter to avoid muddiness
                if ($energyDiff > 0.4) {
                    $crossfadeMs = (int) ($crossfadeMs * 0.7);
                }
                break;
        }

        // Clamp to reasonable range
        $crossfadeMs = max(300, min(6000, $crossfadeMs));

        return ['crossfade_ms' => $crossfadeMs];
    }
}
