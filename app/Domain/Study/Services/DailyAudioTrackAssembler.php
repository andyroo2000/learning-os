<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Results\DailyAudioScriptUnit;
use App\Domain\Study\Results\DailyAudioTrackAssemblyResult;
use App\Domain\Study\Support\DailyAudioPracticeId;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class DailyAudioTrackAssembler
{
    public const MAX_SCRIPT_UNITS = 5_000;

    public function __construct(
        private readonly FishAudioSpeechGenerator $speech,
        private readonly DailyAudioAudioProcessor $audio,
    ) {}

    /**
     * @param  iterable<int, DailyAudioScriptUnit>  $scriptUnits
     */
    public function assemble(
        string $practiceId,
        string $trackId,
        iterable $scriptUnits,
    ): DailyAudioTrackAssemblyResult {
        $practiceId = strtolower(trim($practiceId));
        $trackId = strtolower(trim($trackId));

        if (! DailyAudioPracticeId::isValid($practiceId)
            || ! DailyAudioPracticeId::isValid($trackId)) {
            throw new InvalidArgumentException('Daily Audio assembly requires valid practice and track IDs.');
        }

        $units = collect($scriptUnits)->values();
        if ($units->isEmpty() || $units->count() > self::MAX_SCRIPT_UNITS) {
            throw new InvalidArgumentException('Daily Audio script unit count is invalid.');
        }
        if ($units->contains(
            fn (mixed $unit): bool => ! $unit instanceof DailyAudioScriptUnit,
        )) {
            throw new InvalidArgumentException('Daily Audio assembly requires typed script units.');
        }
        if ($units->every(
            fn (DailyAudioScriptUnit $unit): bool => $unit->type === 'marker',
        )) {
            throw new InvalidArgumentException('Daily Audio assembly requires at least one audio unit.');
        }

        $directory = sys_get_temp_dir().'/learning-os-daily-audio-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Daily Audio temporary directory could not be created.');
        }

        try {
            return $this->assembleIn($practiceId, $trackId, $units->all(), $directory);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    /**
     * @param  list<DailyAudioScriptUnit>  $units
     */
    private function assembleIn(
        string $practiceId,
        string $trackId,
        array $units,
        string $directory,
    ): DailyAudioTrackAssemblyResult {
        $segmentPaths = [];
        $durations = [];
        $syntheses = [];
        $silences = [];
        $spokenUnitCount = 0;
        $pauseUnitCount = 0;
        $reusedSynthesisCount = 0;

        foreach ($units as $index => $unit) {
            if ($unit->type === 'marker') {
                continue;
            }

            if ($unit->type === 'pause') {
                $pauseUnitCount++;
                $cacheKey = number_format((float) $unit->seconds, 3, '.', '');
                if (! isset($silences[$cacheKey])) {
                    $path = $directory.'/silence-'.count($silences).'.mp3';
                    $this->audio->silence((float) $unit->seconds, $path);
                    $silences[$cacheKey] = [
                        'path' => $path,
                        'duration' => $this->audio->duration($path),
                    ];
                }
                $segment = $silences[$cacheKey];
            } else {
                $spokenUnitCount++;
                $speed = $unit->speed ?? 1.0;
                $cacheKey = hash('sha256', implode("\0", [
                    (string) $unit->voiceId,
                    (string) $unit->text,
                    number_format($speed, 3, '.', ''),
                ]));
                if (! isset($syntheses[$cacheKey])) {
                    $syntheses[$cacheKey] = $this->synthesize(
                        text: (string) $unit->text,
                        voiceId: (string) $unit->voiceId,
                        speed: $speed,
                        directory: $directory,
                        sequence: count($syntheses),
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
        $storagePath = "daily-audio/{$practiceId}/{$trackId}.mp3";
        $stream = fopen($outputPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Daily Audio track could not be opened for persistence.');
        }

        try {
            $stored = Storage::disk((string) config('daily_audio.disk'))->put(
                $storagePath,
                $stream,
            );
        } finally {
            fclose($stream);
        }
        if (! $stored) {
            throw new RuntimeException('Daily Audio track could not be persisted.');
        }

        return new DailyAudioTrackAssemblyResult(
            storagePath: $storagePath,
            durationSeconds: max(1, (int) round($actualDuration)),
            timingData: $this->timingData($units, $durations, $actualDuration),
            metadata: [
                'unitCount' => count($units),
                'spokenUnitCount' => $spokenUnitCount,
                'pauseUnitCount' => $pauseUnitCount,
                'uniqueSynthesisCount' => count($syntheses),
                'reusedSynthesisCount' => $reusedSynthesisCount,
            ],
        );
    }

    /**
     * @return array{path: string, duration: float}
     */
    private function synthesize(
        string $text,
        string $voiceId,
        float $speed,
        string $directory,
        int $sequence,
    ): array {
        $maximumDuration = max(10.0, mb_strlen($text, 'UTF-8') * 0.5)
            / min(1.0, $speed);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $rawPath = "{$directory}/speech-{$sequence}-{$attempt}-raw.mp3";
            $normalizedPath = "{$directory}/speech-{$sequence}-{$attempt}.mp3";
            if (file_put_contents(
                $rawPath,
                $this->speech->generate($text, $voiceId, $speed),
                LOCK_EX,
            ) === false) {
                throw new RuntimeException('Daily Audio speech segment could not be written.');
            }

            $this->audio->normalize($rawPath, $normalizedPath);
            $duration = $this->audio->duration($normalizedPath);
            if ($duration <= $maximumDuration) {
                return ['path' => $normalizedPath, 'duration' => $duration];
            }
        }

        $truncatedPath = "{$directory}/speech-{$sequence}-truncated.mp3";
        $this->audio->truncate($normalizedPath, $maximumDuration, $truncatedPath);

        return [
            'path' => $truncatedPath,
            'duration' => $this->audio->duration($truncatedPath),
        ];
    }

    /**
     * @param  list<DailyAudioScriptUnit>  $units
     * @param  array<int, float>  $durations
     * @return list<array{unitIndex: int, startTime: int, endTime: int}>
     */
    private function timingData(
        array $units,
        array $durations,
        float $actualDuration,
    ): array {
        $timingData = [];
        $elapsed = 0.0;
        $measuredDuration = array_sum($durations);
        if ($measuredDuration <= 0) {
            throw new RuntimeException('Daily Audio segment duration is invalid.');
        }
        // Re-encoding can alter encoder padding. Reconcile every boundary to the
        // authoritative final file so the last timing always matches its duration.
        $durationScale = $actualDuration / $measuredDuration;

        foreach ($units as $index => $unit) {
            if ($unit->type === 'marker') {
                continue;
            }

            $duration = $durations[$index]
                ?? throw new RuntimeException('Daily Audio segment timing is incomplete.');
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
                new \RecursiveDirectoryIterator(
                    $directory,
                    \FilesystemIterator::SKIP_DOTS,
                ),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($directory);
        } catch (Throwable) {
            // Temporary cleanup must not hide the assembly result or its original failure.
        }
    }
}
