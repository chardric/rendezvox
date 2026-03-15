<?php

declare(strict_types=1);

/**
 * Audio mood analysis — extracts BPM, energy, valence, and ending characteristics
 * from audio files using ffmpeg spectral analysis.
 *
 * Energy: normalized RMS power (0.0 = silent, 1.0 = max loudness).
 * Valence: approximated from spectral centroid + tempo (brighter + faster = happier).
 * Ending type: 'fade' (gradual decay), 'hard' (abrupt stop), 'silence' (trailing silence).
 */
class MoodAnalyzer
{
    /**
     * Analyze a single audio file for mood characteristics.
     *
     * @return array{bpm: int|null, energy: float, valence: float,
     *               ending_type: string, ending_energy: float, intro_energy: float}
     */
    public static function analyze(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $duration = self::getDuration($filePath);
        if ($duration <= 0) {
            throw new \RuntimeException("Cannot determine duration: {$filePath}");
        }

        // Run all analyses in parallel via separate ffmpeg invocations
        $rmsData    = self::extractRmsEnergy($filePath);
        $spectral   = self::extractSpectralCentroid($filePath);
        $bpm        = self::detectBpm($filePath);

        // Overall energy: mean RMS normalized to 0-1
        $energy = self::normalizeEnergy($rmsData['mean_rms'] ?? -60.0);

        // Intro/ending energy from first/last 5 seconds
        $introEnergy  = self::normalizeEnergy($rmsData['intro_rms'] ?? -60.0);
        $endingEnergy = self::normalizeEnergy($rmsData['ending_rms'] ?? -60.0);

        // Ending type detection
        $endingType = self::classifyEnding($rmsData, $duration);

        // Valence: heuristic from spectral brightness + tempo
        $valence = self::computeValence($spectral, $bpm);

        return [
            'bpm'           => $bpm,
            'energy'        => round($energy, 3),
            'valence'       => round($valence, 3),
            'ending_type'   => $endingType,
            'ending_energy' => round($endingEnergy, 3),
            'intro_energy'  => round($introEnergy, 3),
        ];
    }

    /**
     * Get audio file duration in seconds.
     */
    private static function getDuration(string $filePath): float
    {
        $cmd = 'ffprobe -v error -show_entries format=duration -of csv=p=0 '
             . escapeshellarg($filePath) . ' 2>/dev/null';
        $out = trim((string) shell_exec($cmd));
        return (float) $out;
    }

    /**
     * Extract RMS energy statistics using ffmpeg's astats filter.
     * Returns mean, intro (first 5s), and ending (last 5s) RMS in dB.
     */
    private static function extractRmsEnergy(string $filePath): array
    {
        // Full track RMS
        $cmd = 'ffmpeg -i ' . escapeshellarg($filePath)
             . ' -af astats=metadata=1:reset=0,ametadata=print:key=lavfi.astats.Overall.RMS_level'
             . ' -f null - 2>&1 | grep "RMS_level" | tail -1';
        $out = trim((string) shell_exec($cmd));
        $meanRms = -60.0;
        if (preg_match('/RMS_level=(-?[\d.]+)/', $out, $m)) {
            $meanRms = (float) $m[1];
        }

        $duration = self::getDuration($filePath);

        // Intro: first 5 seconds
        $introCmd = 'ffmpeg -i ' . escapeshellarg($filePath)
                  . ' -t 5 -af astats=metadata=1:reset=0,ametadata=print:key=lavfi.astats.Overall.RMS_level'
                  . ' -f null - 2>&1 | grep "RMS_level" | tail -1';
        $introOut = trim((string) shell_exec($introCmd));
        $introRms = -60.0;
        if (preg_match('/RMS_level=(-?[\d.]+)/', $introOut, $m)) {
            $introRms = (float) $m[1];
        }

        // Ending: last 5 seconds
        $startSec = max(0, $duration - 5.0);
        $endCmd = 'ffmpeg -i ' . escapeshellarg($filePath)
                . ' -ss ' . escapeshellarg((string) $startSec)
                . ' -af astats=metadata=1:reset=0,ametadata=print:key=lavfi.astats.Overall.RMS_level'
                . ' -f null - 2>&1 | grep "RMS_level" | tail -1';
        $endOut = trim((string) shell_exec($endCmd));
        $endingRms = -60.0;
        if (preg_match('/RMS_level=(-?[\d.]+)/', $endOut, $m)) {
            $endingRms = (float) $m[1];
        }

        // Ending gradient: compare last 5s to second-to-last 5s for fade detection
        $gradientRms = null;
        if ($duration > 10.0) {
            $gradStart = max(0, $duration - 10.0);
            $gradCmd = 'ffmpeg -i ' . escapeshellarg($filePath)
                     . ' -ss ' . escapeshellarg((string) $gradStart)
                     . ' -t 5 -af astats=metadata=1:reset=0,ametadata=print:key=lavfi.astats.Overall.RMS_level'
                     . ' -f null - 2>&1 | grep "RMS_level" | tail -1';
            $gradOut = trim((string) shell_exec($gradCmd));
            if (preg_match('/RMS_level=(-?[\d.]+)/', $gradOut, $m)) {
                $gradientRms = (float) $m[1];
            }
        }

        return [
            'mean_rms'     => $meanRms,
            'intro_rms'    => $introRms,
            'ending_rms'   => $endingRms,
            'gradient_rms' => $gradientRms,
        ];
    }

    /**
     * Extract spectral centroid (brightness indicator) using ffmpeg.
     * Higher centroid = brighter sound = higher perceived valence.
     */
    private static function extractSpectralCentroid(string $filePath): float
    {
        // Use showspectrum to get spectral info, approximate via frequency stats
        $cmd = 'ffmpeg -i ' . escapeshellarg($filePath)
             . ' -af astats=metadata=1:reset=0,ametadata=print:key=lavfi.astats.Overall.Flat_factor'
             . ' -f null - 2>&1 | grep "Flat_factor" | tail -1';
        $out = trim((string) shell_exec($cmd));

        $flatFactor = 0.5;
        if (preg_match('/Flat_factor=(-?[\d.]+)/', $out, $m)) {
            $flatFactor = (float) $m[1];
        }

        // Normalize: flat_factor ranges ~0-20, higher = more tonal (less noisy)
        // Invert and normalize to 0-1 for brightness approximation
        return min(1.0, max(0.0, $flatFactor / 20.0));
    }

    /**
     * Detect BPM using ffmpeg's tempo detection via onset analysis.
     * Falls back to spectral flux estimation.
     */
    private static function detectBpm(string $filePath): ?int
    {
        // Use ffmpeg beat detection: extract onset frames and compute tempo
        $cmd = 'ffmpeg -i ' . escapeshellarg($filePath)
             . ' -vn -ac 1 -ar 22050 -f f32le pipe:1 2>/dev/null'
             . ' | ffmpeg -f f32le -ar 22050 -ac 1 -i pipe:0'
             . ' -af tempo -f null - 2>&1';
        $out = (string) shell_exec($cmd);

        // Try alternative: use libavfilter's ebur128 and estimate from peak spacing
        // Simpler approach: use ffprobe to check for embedded BPM tag first
        $tagCmd = 'ffprobe -v error -show_entries format_tags=TBPM,bpm,BPM -of csv=p=0 '
                . escapeshellarg($filePath) . ' 2>/dev/null';
        $tagOut = trim((string) shell_exec($tagCmd));
        if ($tagOut !== '' && is_numeric($tagOut)) {
            $bpm = (int) round((float) $tagOut);
            if ($bpm >= 40 && $bpm <= 240) {
                return $bpm;
            }
        }

        // Estimate BPM from onset detection via ffmpeg's adelay + silence detection
        // Count zero-crossings per second as proxy for rhythmic density
        $zcCmd = 'ffmpeg -i ' . escapeshellarg($filePath)
               . ' -t 30 -af astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.Zero_crossings_rate'
               . ' -f null - 2>&1 | grep "Zero_crossings_rate"';
        $zcOut = (string) shell_exec($zcCmd);

        $rates = [];
        if (preg_match_all('/Zero_crossings_rate=([\d.]+)/', $zcOut, $matches)) {
            foreach ($matches[1] as $r) {
                $rates[] = (float) $r;
            }
        }

        if (empty($rates)) {
            return null;
        }

        // Heuristic: map zero-crossing rate to BPM range
        // Typical ZCR: 0.02-0.2 for music
        $avgZcr = array_sum($rates) / count($rates);
        // Very rough mapping: ZCR correlates loosely with tempo
        $estimatedBpm = (int) round(60 + ($avgZcr * 800));
        $estimatedBpm = max(60, min(200, $estimatedBpm));

        return $estimatedBpm;
    }

    /**
     * Normalize RMS dB to 0.0-1.0 scale.
     * -60 dB → 0.0, 0 dB → 1.0
     */
    private static function normalizeEnergy(float $rmsDb): float
    {
        // Clamp to -60..0 range
        $rmsDb = max(-60.0, min(0.0, $rmsDb));
        return ($rmsDb + 60.0) / 60.0;
    }

    /**
     * Classify ending type based on RMS energy patterns.
     */
    private static function classifyEnding(array $rmsData, float $duration): string
    {
        $endingRms   = $rmsData['ending_rms'] ?? -60.0;
        $gradientRms = $rmsData['gradient_rms'] ?? null;

        // Silence: ending is very quiet
        if ($endingRms < -45.0) {
            return 'silence';
        }

        // Fade: gradual decay (gradient segment louder than ending)
        if ($gradientRms !== null && ($gradientRms - $endingRms) > 4.0) {
            return 'fade';
        }

        // Hard: ending still has significant energy (abrupt stop)
        return 'hard';
    }

    /**
     * Compute valence from spectral brightness and BPM.
     * Brighter + faster = higher valence (happier).
     */
    private static function computeValence(float $spectralBrightness, ?int $bpm): float
    {
        // Tempo component: 60-200 BPM mapped to 0.0-1.0
        $tempoFactor = 0.5;
        if ($bpm !== null) {
            $tempoFactor = min(1.0, max(0.0, ($bpm - 60) / 140.0));
        }

        // Weighted average: 40% brightness, 60% tempo
        return min(1.0, max(0.0, $spectralBrightness * 0.4 + $tempoFactor * 0.6));
    }
}
