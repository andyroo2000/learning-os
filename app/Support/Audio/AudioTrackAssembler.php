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
        $request = new AudioTrackAssemblyRequest($disk, $storagePath, $temporaryPrefix, $label);
        $units = collect($scriptUnits)->values();
        $this->assertValidUnitCount($units->all(), $request);
        $this->assertTypedUnits($units->all(), $request);
        $this->assertSupportedUnitTypes($units->all(), $request);
        $this->assertHasAudioUnit($units->all(), $request);
        $this->assertValidStorageTarget($request);
        $this->assertValidUnitSettings($units->all(), $request);

        $directory = $this->createTemporaryDirectory($request);
        $workspace = compact('request', 'directory');

        try {
            return $this->assembleIn($units->all(), $workspace);
        } finally {
            $this->deleteDirectory($directory);
        }
    }

    /** @param list<mixed> $units */
    private function assertValidUnitCount(array $units, AudioTrackAssemblyRequest $request): void
    {
        if ($units === [] || count($units) > self::MAX_SCRIPT_UNITS) {
            throw new InvalidArgumentException("{$request->label} script unit count is invalid.");
        }
    }

    /** @param list<mixed> $units */
    private function assertTypedUnits(array $units, AudioTrackAssemblyRequest $request): void
    {
        if (collect($units)->contains(fn (mixed $unit): bool => ! $unit instanceof AudioScriptUnit)) {
            throw new InvalidArgumentException("{$request->label} assembly requires typed script units.");
        }
    }

    /** @param list<AudioScriptUnit> $units */
    private function assertSupportedUnitTypes(array $units, AudioTrackAssemblyRequest $request): void
    {
        if (collect($units)->contains(fn (AudioScriptUnit $unit): bool => ! in_array(
            $unit->audioType(),
            ['marker', 'narration_L1', 'pause', 'L2'],
            true,
        ))) {
            throw new InvalidArgumentException("{$request->label} script unit type is invalid.");
        }
    }

    /** @param list<AudioScriptUnit> $units */
    private function assertHasAudioUnit(array $units, AudioTrackAssemblyRequest $request): void
    {
        if (collect($units)->every(fn (AudioScriptUnit $unit): bool => $unit->audioType() === 'marker')) {
            throw new InvalidArgumentException("{$request->label} assembly requires at least one audio unit.");
        }
    }

    private function assertValidStorageTarget(AudioTrackAssemblyRequest $request): void
    {
        if ($request->disk === '') {
            throw new InvalidArgumentException("{$request->label} storage target is invalid.");
        }
        if ($request->storagePath === '') {
            throw new InvalidArgumentException("{$request->label} storage target is invalid.");
        }
        if ($this->unsafeStoragePath($request->storagePath)) {
            throw new InvalidArgumentException("{$request->label} storage target is invalid.");
        }
    }

    private function unsafeStoragePath(string $storagePath): bool
    {
        return str_starts_with($storagePath, '/')
            || str_contains($storagePath, '..')
            || str_contains($storagePath, '\\');
    }

    /** @param list<AudioScriptUnit> $units */
    private function assertValidUnitSettings(array $units, AudioTrackAssemblyRequest $request): void
    {
        foreach ($units as $unit) {
            $this->assertValidSpeechSpeed($unit->audioSpeed(), $request);
            $this->assertValidPause($unit, $request);
        }
    }

    private function assertValidSpeechSpeed(?float $speed, AudioTrackAssemblyRequest $request): void
    {
        if ($speed === null) {
            return;
        }
        if (! $this->validSpeechSpeed($speed)) {
            throw new InvalidArgumentException("{$request->label} speech speed is invalid.");
        }
    }

    private function validSpeechSpeed(float $speed): bool
    {
        return is_finite($speed) && $speed >= 0.5 && $speed <= 2;
    }

    private function assertValidPause(AudioScriptUnit $unit, AudioTrackAssemblyRequest $request): void
    {
        if ($unit->audioType() !== 'pause') {
            return;
        }

        $pause = $unit->audioPauseSeconds();
        if ($pause === null) {
            throw new InvalidArgumentException("{$request->label} pause duration is invalid.");
        }
        if (! $this->validPause($pause)) {
            throw new InvalidArgumentException("{$request->label} pause duration is invalid.");
        }
    }

    private function validPause(float $pause): bool
    {
        return is_finite($pause) && $pause > 0 && $pause <= 60;
    }

    private function createTemporaryDirectory(AudioTrackAssemblyRequest $request): string
    {
        $safePrefix = preg_replace('/[^a-z0-9-]+/i', '-', trim($request->temporaryPrefix));
        if (! is_string($safePrefix) || trim($safePrefix, '-') === '') {
            throw new InvalidArgumentException("{$request->label} temporary prefix is invalid.");
        }
        $directory = sys_get_temp_dir().'/'.trim($safePrefix, '-').'-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("{$request->label} temporary directory could not be created.");
        }

        return $directory;
    }

    /**
     * @param  list<AudioScriptUnit>  $units
     * @param  array{request:AudioTrackAssemblyRequest,directory:string}  $workspace
     */
    private function assembleIn(array $units, array $workspace): AudioTrackAssemblyResult
    {
        $segments = $this->buildSegments($units, $workspace);
        $outputPath = $workspace['directory'].'/track.mp3';
        $this->audio->concatenate($segments['paths'], $workspace['directory'], $outputPath);
        $actualDuration = $this->audio->duration($outputPath);
        $timingData = $this->timingData(
            $units,
            $segments['durations'],
            $actualDuration,
            $workspace['request']->label,
        );
        $this->persistTrack($outputPath, $workspace);

        return new AudioTrackAssemblyResult(
            storagePath: $workspace['request']->storagePath,
            durationSeconds: max(1, (int) round($actualDuration)),
            timingData: $timingData,
            metadata: [
                'unitCount' => count($units),
                'spokenUnitCount' => $segments['spokenUnitCount'],
                'pauseUnitCount' => $segments['pauseUnitCount'],
                'uniqueSynthesisCount' => count($segments['syntheses']),
                'reusedSynthesisCount' => $segments['reusedSynthesisCount'],
            ],
        );
    }

    /**
     * @param  list<AudioScriptUnit>  $units
     * @param  array{request:AudioTrackAssemblyRequest,directory:string}  $workspace
     * @return array{
     *   paths:list<string>,durations:array<int,float>,syntheses:array<string,array{path:string,duration:float}>,
     *   silences:array<string,array{path:string,duration:float}>,spokenUnitCount:int,pauseUnitCount:int,
     *   reusedSynthesisCount:int
     * }
     */
    private function buildSegments(array $units, array $workspace): array
    {
        $segments = [
            'paths' => [],
            'durations' => [],
            'syntheses' => [],
            'silences' => [],
            'spokenUnitCount' => 0,
            'pauseUnitCount' => 0,
            'reusedSynthesisCount' => 0,
        ];

        foreach ($units as $index => $unit) {
            if ($unit->audioType() === 'marker') {
                continue;
            }

            if ($unit->audioType() === 'pause') {
                $segments['pauseUnitCount']++;
                $segment = $this->pauseSegment($unit, $workspace, $segments['silences']);
            } else {
                $segments['spokenUnitCount']++;
                $segment = $this->spokenSegment($unit, $workspace, $segments);
            }

            $segments['paths'][] = $segment['path'];
            $segments['durations'][$index] = $segment['duration'];
        }

        return $segments;
    }

    /**
     * @param  array{request:AudioTrackAssemblyRequest,directory:string}  $workspace
     * @param  array<string,array{path:string,duration:float}>  $silences
     * @return array{path:string,duration:float}
     */
    private function pauseSegment(AudioScriptUnit $unit, array $workspace, array &$silences): array
    {
        $seconds = $unit->audioPauseSeconds()
            ?? throw new InvalidArgumentException("{$workspace['request']->label} pause duration is missing.");
        $cacheKey = number_format($seconds, 3, '.', '');
        if (! isset($silences[$cacheKey])) {
            $path = $workspace['directory'].'/silence-'.count($silences).'.mp3';
            $this->audio->silence($seconds, $path);
            $silences[$cacheKey] = ['path' => $path, 'duration' => $this->audio->duration($path)];
        }

        return $silences[$cacheKey];
    }

    /**
     * @param  array{request:AudioTrackAssemblyRequest,directory:string}  $workspace
     * @param  array{
     *   paths:list<string>,durations:array<int,float>,syntheses:array<string,array{path:string,duration:float}>,
     *   silences:array<string,array{path:string,duration:float}>,spokenUnitCount:int,pauseUnitCount:int,
     *   reusedSynthesisCount:int
     * }  $segments
     * @return array{path:string,duration:float}
     */
    private function spokenSegment(AudioScriptUnit $unit, array $workspace, array &$segments): array
    {
        $speech = $this->speechUnit($unit, $workspace['request']);
        $cacheKey = hash('sha256', implode("\0", [
            $speech['voiceId'], $speech['text'], number_format($speech['speed'], 3, '.', ''),
        ]));
        if (isset($segments['syntheses'][$cacheKey])) {
            $segments['reusedSynthesisCount']++;

            return $segments['syntheses'][$cacheKey];
        }

        return $segments['syntheses'][$cacheKey] = $this->synthesize(
            $speech,
            $workspace,
            count($segments['syntheses']),
        );
    }

    /** @return array{text:string,voiceId:string,speed:float} */
    private function speechUnit(AudioScriptUnit $unit, AudioTrackAssemblyRequest $request): array
    {
        return [
            'text' => $unit->audioText()
                ?? throw new InvalidArgumentException("{$request->label} spoken text is missing."),
            'voiceId' => $unit->audioVoiceId()
                ?? throw new InvalidArgumentException("{$request->label} voice ID is missing."),
            'speed' => $unit->audioSpeed() ?? 1.0,
        ];
    }

    /** @param array{request:AudioTrackAssemblyRequest,directory:string} $workspace */
    private function persistTrack(string $outputPath, array $workspace): void
    {
        $stream = fopen($outputPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("{$workspace['request']->label} track could not be opened for persistence.");
        }

        try {
            $stored = Storage::disk($workspace['request']->disk)->put($workspace['request']->storagePath, $stream);
        } finally {
            fclose($stream);
        }
        if (! $stored) {
            throw new RuntimeException("{$workspace['request']->label} track could not be persisted.");
        }
    }

    /**
     * @param  array{text:string,voiceId:string,speed:float}  $speech
     * @param  array{request:AudioTrackAssemblyRequest,directory:string}  $workspace
     * @return array{path: string, duration: float}
     */
    private function synthesize(array $speech, array $workspace, int $sequence): array
    {
        $maximumDuration = max(10.0, mb_strlen($speech['text'], 'UTF-8') * 0.5)
            / min(1.0, $speech['speed']);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $rawPath = "{$workspace['directory']}/speech-{$sequence}-{$attempt}-raw.mp3";
            $normalizedPath = "{$workspace['directory']}/speech-{$sequence}-{$attempt}.mp3";
            if (file_put_contents(
                $rawPath,
                $this->speech->generate($speech['text'], $speech['voiceId'], $speech['speed']),
                LOCK_EX,
            ) === false) {
                throw new RuntimeException("{$workspace['request']->label} speech segment could not be written.");
            }
            $this->audio->normalize($rawPath, $normalizedPath);
            $duration = $this->audio->duration($normalizedPath);
            if ($duration <= $maximumDuration) {
                return ['path' => $normalizedPath, 'duration' => $duration];
            }
        }

        $truncatedPath = "{$workspace['directory']}/speech-{$sequence}-truncated.mp3";
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
