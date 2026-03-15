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
     * Detect BPM using embedded tags or onset-interval histogram.
     */
    private static function detectBpm(string $filePath): ?int
    {
        // 1. Check embedded BPM tags (ID3 TBPM, Vorbis BPM/TEMPO)
        $esc = escapeshellarg($filePath);
        $tagCmd = "ffprobe -v error -show_entries format_tags=TBPM,bpm,BPM"
                . " -show_entries stream_tags=TBPM,bpm,BPM"
                . " -of csv=p=0 {$esc} 2>/dev/null";
        $tagOut = trim((string) shell_exec($tagCmd));
        foreach (explode("\n", $tagOut) as $line) {
            $line = trim($line, " \t\n\r,");
            if ($line !== '' && is_numeric($line)) {
                $bpm = (int) round((float) $line);
                if ($bpm >= 40 && $bpm <= 240) {
                    return $bpm;
                }
            }
        }

        // 2. Onset-interval BPM via raw PCM analysis
        //    Pipe 30s mono audio at 8kHz, compute RMS in 20ms frames (50Hz),
        //    detect onsets, build IOI histogram → BPM.
        $cmd = "ffmpeg -i {$esc} -t 30 -ac 1 -ar 8000"
             . ' -f s16le -acodec pcm_s16le pipe:1 2>/dev/null';
        $raw = (string) shell_exec($cmd);

        $numSamples = (int) (strlen($raw) / 2);
        if ($numSamples < 40000) {
            return null; // Need 5+ seconds
        }

        // Unpack all 16-bit signed samples
        /** @var int[] $samples */
        $samples = array_values(unpack('v*', $raw));
        foreach ($samples as &$s) {
            if ($s >= 32768) {
                $s -= 65536;
            }
        }
        unset($s);

        // Compute RMS per 20ms frame (160 samples at 8kHz) → 50Hz resolution
        $frameSize = 160;
        $rms = [];
        for ($i = 0; $i + $frameSize <= $numSamples; $i += $frameSize) {
            $sum = 0.0;
            for ($j = $i; $j < $i + $frameSize; $j++) {
                $sum += (float) $samples[$j] * (float) $samples[$j];
            }
            $rms[] = sqrt($sum / $frameSize);
        }

        if (count($rms) < 250) {
            return null; // Need 5+ seconds at 50Hz
        }

        // Onset detection: positive energy increase between frames
        $onset = [];
        for ($i = 1; $i < count($rms); $i++) {
            $onset[] = max(0.0, $rms[$i] - $rms[$i - 1]);
        }

        // Smooth with 3-frame moving average (60ms)
        $smooth = [];
        for ($i = 1; $i < count($onset) - 1; $i++) {
            $smooth[] = ($onset[$i - 1] + $onset[$i] + $onset[$i + 1]) / 3.0;
        }

        $maxVal = !empty($smooth) ? max($smooth) : 0.0;
        if ($maxVal <= 0) {
            return null;
        }

        // Find peaks above 15% of max
        $threshold = $maxVal * 0.15;
        $peaks = [];
        for ($i = 1; $i < count($smooth) - 1; $i++) {
            if ($smooth[$i] > $threshold
                && $smooth[$i] > $smooth[$i - 1]
                && $smooth[$i] >= $smooth[$i + 1]) {
                $peaks[] = $i;
            }
        }

        if (count($peaks) < 8) {
            return null;
        }

        // IOI histogram: BPM 60–200 at 50Hz → IOI 15–50 frames
        $fps = 50.0;
        $hist = array_fill(0, 51, 0.0);
        for ($i = 1; $i < count($peaks); $i++) {
            $ioi = $peaks[$i] - $peaks[$i - 1];
            $bin = (int) round($ioi);
            if ($bin >= 15 && $bin <= 50) {
                $hist[$bin] += 1.0;
            }
            // Half-time vote (octave awareness)
            $dbl = (int) round($ioi * 2);
            if ($dbl >= 15 && $dbl <= 50) {
                $hist[$dbl] += 0.5;
            }
        }

        // Find histogram peak
        $bestBin = 0;
        $bestCount = 0.0;
        for ($i = 15; $i <= 50; $i++) {
            if ($hist[$i] > $bestCount) {
                $bestCount = $hist[$i];
                $bestBin = $i;
            }
        }

        if ($bestBin === 0 || $bestCount < 3) {
            return null;
        }

        $bpm = (int) round($fps * 60.0 / $bestBin);

        // Octave folding: most music sits in 70–140 BPM.
        // Onset detection often locks to subdivisions (8th/16th notes),
        // yielding 2x the actual tempo. Fold into the sweet spot.
        while ($bpm > 140 && $bpm / 2 >= 55) {
            $bpm = (int) round($bpm / 2.0);
        }
        while ($bpm < 55 && $bpm * 2 <= 210) {
            $bpm *= 2;
        }

        return ($bpm >= 50 && $bpm <= 210) ? $bpm : null;
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
