<?php

namespace App\Support\Audio;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AudioTrackAssembler
{
    public const MAX_SCRIPT_UNITS = 5_000;

    public function __construct(
        private readonly AudioSpeechGenerator $speech,
        private readonly AudioProcessor $audio,
    ) {}

    /** @param iterable<int, AudioScriptUnit> $scriptUnits */
    public function assemble(
        iterable $scriptUnits,
        string $disk,
        string $storagePath,
        string $temporaryPrefix,
        string $label,
    ): AudioTrackAssemblyResult {
        $units = collect($scriptUnits)->values();
        if ($units->isEmpty() || $units->count() > self::MAX_SCRIPT_UNITS) {
            throw new InvalidArgumentException("{$label} script unit count is invalid.");
        }
        if ($units->contains(fn (mixed $unit): bool => ! $unit instanceof AudioScriptUnit)) {
            throw new InvalidArgumentException("{$label} assembly requires typed script units.");
        }
        if ($units->contains(fn (AudioScriptUnit $unit): bool => ! in_array(
            $unit->audioType(),
            ['marker', 'narration_L1', 'pause', 'L2'],
            true,
        ))) {
            throw new InvalidArgumentException("{$label} script unit type is invalid.");
        }
        if ($units->every(fn (AudioScriptUnit $unit): bool => $unit->audioType() === 'marker')) {
            throw new InvalidArgumentException("{$label} assembly requires at least one audio unit.");
        }
        if ($disk === '' || $storagePath === '' || str_starts_with($storagePath, '/')
            || str_contains($storagePath, '..') || str_contains($storagePath, '\\')) {
            throw new InvalidArgumentException("{$label} storage target is invalid.");
        }

        foreach ($units as $unit) {
            $speed = $unit->audioSpeed();
            if ($speed !== null && (! is_finite($speed) || $speed < 0.5 || $speed > 2)) {
                throw new InvalidArgumentException("{$label} speech speed is invalid.");
            }
            $pause = $unit->audioPauseSeconds();
            if ($unit->audioType() === 'pause'
                && ($pause === null || ! is_finite($pause) || $pause <= 0 || $pause > 60)) {
                throw new InvalidArgumentException("{$label} pause duration is invalid.");
            }
        }

        $safePrefix = preg_replace('/[^a-z0-9-]+/i', '-', trim($temporaryPrefix));
        if (! is_string($safePrefix) || trim($safePrefix, '-') === '') {
            throw new InvalidArgumentException("{$label} temporary prefix is invalid.");
        }
        $directory = sys_get_temp_dir().'/'.trim($safePrefix, '-').'-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("{$label} temporary directory could not be created.");
        }

        try {
            return $this->assembleIn($units->all(), $disk, $storagePath, $directory, $label);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    /** @param list<AudioScriptUnit> $units */
    private function assembleIn(
        array $units,
        string $disk,
        string $storagePath,
        string $directory,
        string $label,
    ): AudioTrackAssemblyResult {
        $segmentPaths = [];
        $durations = [];
        $syntheses = [];
        $silences = [];
        $spokenUnitCount = 0;
        $pauseUnitCount = 0;
        $reusedSynthesisCount = 0;

        foreach ($units as $index => $unit) {
            if ($unit->audioType() === 'marker') {
                continue;
            }

            if ($unit->audioType() === 'pause') {
                $pauseUnitCount++;
                $seconds = $unit->audioPauseSeconds()
                    ?? throw new InvalidArgumentException("{$label} pause duration is missing.");
                $cacheKey = number_format($seconds, 3, '.', '');
                if (! isset($silences[$cacheKey])) {
                    $path = $directory.'/silence-'.count($silences).'.mp3';
                    $this->audio->silence($seconds, $path);
                    $silences[$cacheKey] = ['path' => $path, 'duration' => $this->audio->duration($path)];
                }
                $segment = $silences[$cacheKey];
            } else {
                $spokenUnitCount++;
                $text = $unit->audioText()
                    ?? throw new InvalidArgumentException("{$label} spoken text is missing.");
                $voiceId = $unit->audioVoiceId()
                    ?? throw new InvalidArgumentException("{$label} voice ID is missing.");
                $speed = $unit->audioSpeed() ?? 1.0;
                $cacheKey = hash('sha256', implode("\0", [
                    $voiceId, $text, number_format($speed, 3, '.', ''),
                ]));
                if (! isset($syntheses[$cacheKey])) {
                    $syntheses[$cacheKey] = $this->synthesize(
                        $text, $voiceId, $speed, $directory, count($syntheses), $label,
                    );
                } else {
                    $reusedSynthesisCount++;
                }
                $segment = $syntheses[$cacheKey];
            }

            $segmentPaths[] = $segment['path'];
            $durations[$index] = $segment['duration'];
        }

        $outputPath = $directory.'/track.mp3';
        $this->audio->concatenate($segmentPaths, $directory, $outputPath);
        $actualDuration = $this->audio->duration($outputPath);
        $timingData = $this->timingData($units, $durations, $actualDuration, $label);
        $stream = fopen($outputPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("{$label} track could not be opened for persistence.");
        }

        try {
            $stored = Storage::disk($disk)->put($storagePath, $stream);
        } finally {
            fclose($stream);
        }
        if (! $stored) {
            throw new RuntimeException("{$label} track could not be persisted.");
        }

        return new AudioTrackAssemblyResult(
            storagePath: $storagePath,
            durationSeconds: max(1, (int) round($actualDuration)),
            timingData: $timingData,
            metadata: [
                'unitCount' => count($units),
                'spokenUnitCount' => $spokenUnitCount,
                'pauseUnitCount' => $pauseUnitCount,
                'uniqueSynthesisCount' => count($syntheses),
                'reusedSynthesisCount' => $reusedSynthesisCount,
            ],
        );
    }

    /** @return array{path: string, duration: float} */
    private function synthesize(
        string $text,
        string $voiceId,
        float $speed,
        string $directory,
        int $sequence,
        string $label,
    ): array {
        $maximumDuration = max(10.0, mb_strlen($text, 'UTF-8') * 0.5) / min(1.0, $speed);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $rawPath = "{$directory}/speech-{$sequence}-{$attempt}-raw.mp3";
            $normalizedPath = "{$directory}/speech-{$sequence}-{$attempt}.mp3";
            if (file_put_contents(
                $rawPath,
                $this->speech->generate($text, $voiceId, $speed),
                LOCK_EX,
            ) === false) {
                throw new RuntimeException("{$label} speech segment could not be written.");
            }
            $this->audio->normalize($rawPath, $normalizedPath);
            $duration = $this->audio->duration($normalizedPath);
            if ($duration <= $maximumDuration) {
                return ['path' => $normalizedPath, 'duration' => $duration];
            }
        }

        $truncatedPath = "{$directory}/speech-{$sequence}-truncated.mp3";
        $this->audio->truncate($normalizedPath, $maximumDuration, $truncatedPath);

        return ['path' => $truncatedPath, 'duration' => $this->audio->duration($truncatedPath)];
    }

    /**
     * @param  list<AudioScriptUnit>  $units
     * @param  array<int, float>  $durations
     * @return list<array{unitIndex: int, startTime: int, endTime: int}>
     */
    private function timingData(array $units, array $durations, float $actualDuration, string $label): array
    {
        $timingData = [];
        $elapsed = 0.0;
        $measuredDuration = array_sum($durations);
        if ($measuredDuration <= 0) {
            throw new RuntimeException("{$label} segment duration is invalid.");
        }
        $durationScale = $actualDuration / $measuredDuration;

        foreach ($units as $index => $unit) {
            if ($unit->audioType() === 'marker') {
                continue;
            }
            $duration = $durations[$index]
                ?? throw new RuntimeException("{$label} segment timing is incomplete.");
            $start = (int) round($elapsed * 1_000);
            $elapsed += $duration * $durationScale;
            $timingData[] = [
                'unitIndex' => $index,
                'startTime' => $start,
                'endTime' => (int) round($elapsed * 1_000),
            ];
        }

        return $timingData;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        } catch (Throwable) {
            // Temporary cleanup must not hide the assembly result or original failure.
        }
    }
}
