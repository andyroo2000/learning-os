<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSourceDatabase;
use App\Console\Support\ConvoLabDailyAudioTargetValidator;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportConvoLabDailyAudio extends Command
{
    use ConnectsToConvoLabSourceDatabase;

    private const LOCK_TTL_SECONDS = 86400;

    private const LOCK_CACHE_STORE = 'database';

    /** @var array<string, array{id: string, created_at: string, updated_at: string}> */
    private array $practices = [];

    /** @var array<string, array{id: string, practice_id: string, created_at: string, updated_at: string}> */
    private array $trackTimestamps = [];

    protected $signature = 'migration:import-convolab-daily-audio
        {--source-connection=convolab_rehearsal : Temporary source connection name}
        {--source-database= : Restored Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--source-media-root= : Directory containing Convo Lab storage paths exported from GCS}
        {--source-bucket=convolab-storage : GCS bucket encoded in historical audio URLs}
        {--allow-production : Permit the importer to run when APP_ENV=production}
        {--production-confirmation= : Required production phrase: IMPORT DAILY AUDIO INTO <target database>}';

    protected $description = 'Import verified historical Convo Lab Daily Audio files into Learning OS.';

    /**
     * @var array<string, array{
     *     id: string,
     *     practice_id: string,
     *     mode: string,
     *     source_path: string,
     *     destination_path: string,
     *     size_bytes: int,
     *     checksum_sha256: string,
     *     audio_url: string
     * }>
     */
    private array $tracks = [];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('This command must not run in production without --allow-production.');

            return self::FAILURE;
        }

        $createdPaths = [];
        $databaseCommitted = false;
        $lock = null;
        $lockAcquired = false;

        try {
            $target = DB::connection();
            $this->assertProductionConfirmed($target);
            $lock = $this->importLock($target);
            $lockAcquired = $lock->get();

            if (! $lockAcquired) {
                throw new RuntimeException(
                    'Another Convo Lab Daily Audio import is already running for this target database.',
                );
            }

            $source = $this->convoLabSourceConnection();
            $this->assertConvoLabSourceDiffersFromTarget($source, $target);
            $this->assertSourceSchema($source);
            $sourceMediaRoot = $this->convoLabSourceMediaRoot();
            $sourceBucket = $this->sourceBucket();

            $this->info('Preflighting Convo Lab Daily Audio media');

            $this->buildTimestampManifest($source);
            $this->buildTrackManifest($source, $sourceMediaRoot, $sourceBucket);
            $this->assertTargetMatches($target, lockForUpdate: false);
            $this->preflightDestinationFiles();

            $this->line(sprintf(
                'Verified %d historical Daily Audio tracks.',
                count($this->tracks),
            ));

            $createdPaths = $this->copyMissingFiles();

            $target->transaction(function () use ($target): void {
                $this->assertTargetMatches($target, lockForUpdate: true);

                foreach ($this->practices as $practice) {
                    $updated = $target->table('daily_audio_practices')
                        ->where('id', $practice['id'])
                        ->update([
                            'created_at' => $practice['created_at'],
                            'updated_at' => $practice['updated_at'],
                        ]);

                    if ($updated !== 1) {
                        throw new RuntimeException(
                            "Learning OS Daily Audio practice [{$practice['id']}] changed during import.",
                        );
                    }
                }

                foreach ($this->trackTimestamps as $track) {
                    $updated = $target->table('daily_audio_practice_tracks')
                        ->where('id', $track['id'])
                        ->where('practice_id', $track['practice_id'])
                        ->update([
                            'created_at' => $track['created_at'],
                            'updated_at' => $track['updated_at'],
                        ]);

                    if ($updated !== 1) {
                        throw new RuntimeException(
                            "Learning OS Daily Audio track [{$track['id']}] changed during import.",
                        );
                    }
                }

                foreach ($this->tracks as $track) {
                    $updated = $target->table('daily_audio_practice_tracks')
                        ->where('id', $track['id'])
                        ->where('practice_id', $track['practice_id'])
                        ->where('status', 'ready')
                        ->update(['audio_url' => $track['audio_url']]);

                    if ($updated !== 1) {
                        throw new RuntimeException(
                            "Learning OS Daily Audio track [{$track['id']}] changed during import.",
                        );
                    }
                }
            });
            $databaseCommitted = true;

            $this->info(sprintf(
                'Convo Lab Daily Audio import completed: %d verified tracks.',
                count($this->tracks),
            ));
        } catch (Throwable $e) {
            if (! $databaseCommitted) {
                $disk = Storage::disk((string) config('daily_audio.disk'));
                foreach ($createdPaths as $path) {
                    $disk->delete($path);
                }
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($lockAcquired) {
                $lock?->release();
            }
        }

        return self::SUCCESS;
    }

    private function importLock(ConnectionInterface $target): Lock
    {
        return Cache::store(self::LOCK_CACHE_STORE)->lock(
            'migration:import-convolab-daily-audio:'.$target->getDatabaseName(),
            self::LOCK_TTL_SECONDS,
        );
    }

    private function targetValidator(ConnectionInterface $target): ConvoLabDailyAudioTargetValidator
    {
        return new ConvoLabDailyAudioTargetValidator(
            $target,
            $this->practices,
            $this->trackTimestamps,
            $this->tracks,
        );
    }

    private function assertTargetMatches(
        ConnectionInterface $target,
        bool $lockForUpdate,
    ): void {
        $prepareQuery = $lockForUpdate
            ? static fn (Builder $query): Builder => $query->lockForUpdate()
            : static fn (Builder $query): Builder => $query;

        $this->targetValidator($target)->assertMatches($prepareQuery);
    }

    private function assertProductionConfirmed(ConnectionInterface $target): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $expected = 'IMPORT DAILY AUDIO INTO '.$target->getDatabaseName();

        if ($this->option('production-confirmation') !== $expected) {
            throw new RuntimeException(
                "Production Daily Audio import requires --production-confirmation=\"{$expected}\".",
            );
        }
    }

    private function assertSourceSchema(ConnectionInterface $source): void
    {
        foreach (['daily_audio_practices', 'daily_audio_practice_tracks'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Source database is missing expected Convo Lab table [{$table}].");
            }
        }
    }

    private function sourceBucket(): string
    {
        $bucket = trim((string) $this->option('source-bucket'));

        if ($bucket === ''
            || strlen($bucket) > 222
            || preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', $bucket) !== 1) {
            throw new RuntimeException('Source bucket must be a valid GCS bucket name.');
        }

        return $bucket;
    }

    private function buildTimestampManifest(ConnectionInterface $source): void
    {
        $this->practices = [];
        $this->trackTimestamps = [];

        foreach ($source->table('daily_audio_practices')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->get(['id', 'createdAt', 'updatedAt']) as $row) {
            $this->addPracticeTimestamp($row);
        }

        foreach ($source->table('daily_audio_practice_tracks')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->get(['id', 'practiceId', 'createdAt', 'updatedAt']) as $row) {
            $this->addTrackTimestamp($row);
        }
    }

    private function addPracticeTimestamp(object $row): void
    {
        $id = $this->sourceUuid($row->id, 'Daily Audio practice');

        if (isset($this->practices[$id])) {
            throw new RuntimeException("Convo Lab Daily Audio practice [{$row->id}] is duplicated.");
        }

        $this->practices[$id] = [
            'id' => $id,
            'created_at' => $this->sourceTimestamp($row->createdAt, "practice [{$id}] createdAt"),
            'updated_at' => $this->sourceTimestamp($row->updatedAt, "practice [{$id}] updatedAt"),
        ];
    }

    private function addTrackTimestamp(object $row): void
    {
        $id = $this->sourceUuid($row->id, 'Daily Audio track');
        $practiceId = $this->sourceUuid($row->practiceId, 'Daily Audio practice');

        if (! isset($this->practices[$practiceId])) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$row->id}] references a missing practice.",
            );
        }

        if (isset($this->trackTimestamps[$id])) {
            throw new RuntimeException("Convo Lab Daily Audio track [{$row->id}] is duplicated.");
        }

        $this->trackTimestamps[$id] = [
            'id' => $id,
            'practice_id' => $practiceId,
            'created_at' => $this->sourceTimestamp($row->createdAt, "track [{$id}] createdAt"),
            'updated_at' => $this->sourceTimestamp($row->updatedAt, "track [{$id}] updatedAt"),
        ];
    }

    private function buildTrackManifest(
        ConnectionInterface $source,
        string $sourceMediaRoot,
        string $sourceBucket,
    ): void {
        $this->tracks = [];
        $practiceIds = $source->table('daily_audio_practices')
            ->pluck('id')
            ->mapWithKeys(fn (mixed $id): array => [
                $this->sourceUuid($id, 'Daily Audio practice') => true,
            ]);
        $rows = $source->table('daily_audio_practice_tracks')
            ->where(function ($query): void {
                $query->where('status', 'ready')
                    ->orWhereNotNull('audioUrl');
            })
            ->orderBy('createdAt')
            ->orderBy('id')
            ->get(['id', 'practiceId', 'mode', 'status', 'audioUrl']);

        foreach ($rows as $row) {
            $id = $this->sourceUuid($row->id, 'Daily Audio track');
            $practiceId = $this->sourceUuid($row->practiceId, 'Daily Audio practice');

            if (! $practiceIds->has($practiceId)) {
                throw new RuntimeException(
                    "Convo Lab Daily Audio track [{$row->id}] references a missing practice.",
                );
            }

            if (isset($this->tracks[$id])) {
                throw new RuntimeException("Convo Lab Daily Audio track [{$row->id}] is duplicated.");
            }

            if ($row->status !== 'ready'
                || ! is_string($row->audioUrl)
                || trim($row->audioUrl) === '') {
                throw new RuntimeException(
                    "Convo Lab Daily Audio track [{$row->id}] has inconsistent ready media state.",
                );
            }

            $sourceObjectPath = $this->sourceObjectPath(
                $row->audioUrl,
                $sourceBucket,
                $practiceId,
                $id,
            );
            $sourcePath = $this->resolveConvoLabSourceFile(
                $sourceMediaRoot,
                $sourceObjectPath,
                "Convo Lab Daily Audio bytes are missing for track [{$id}] at ".
                "[{$sourceObjectPath}].",
            );
            $size = filesize($sourcePath);
            $checksum = hash_file('sha256', $sourcePath);

            if (! is_int($size) || $size < 1 || $size > MediaAsset::MAX_JSON_SAFE_SIZE_BYTES) {
                throw new RuntimeException("Convo Lab Daily Audio track [{$id}] has an invalid byte size.");
            }

            if (! is_string($checksum)) {
                throw new RuntimeException("Unable to checksum Convo Lab Daily Audio track [{$id}].");
            }

            $destinationPath = DailyAudioPracticeGeneration::storagePath($practiceId, $id);
            $this->tracks[$id] = [
                'id' => $id,
                'practice_id' => $practiceId,
                'mode' => (string) $row->mode,
                'source_path' => $sourcePath,
                'destination_path' => $destinationPath,
                'size_bytes' => $size,
                'checksum_sha256' => $checksum,
                'audio_url' => DailyAudioPracticeGeneration::audioUrl($practiceId, $id),
            ];
        }
    }

    private function sourceUuid(mixed $value, string $label): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        if (! Str::isUuid($normalized)) {
            throw new RuntimeException("Convo Lab {$label} [{$normalized}] does not have a valid UUID.");
        }

        return $normalized;
    }

    private function sourceTimestamp(mixed $value, string $label): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.v');
        }

        $timestamp = is_string($value) ? trim($value) : '';
        $format = str_contains($timestamp, '.') ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $parsed = DateTimeImmutable::createFromFormat($format, $timestamp, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException("Convo Lab Daily Audio {$label} is not a valid database timestamp.");
        }

        return $parsed->format('Y-m-d H:i:s.v');
    }

    private function sourceObjectPath(
        string $audioUrl,
        string $sourceBucket,
        string $practiceId,
        string $trackId,
    ): string {
        $source = [
            'audio_url' => $audioUrl,
            'track_id' => $trackId,
        ];
        $parts = $this->sourceUrlParts($source);
        $path = $parts['path'];
        $prefix = "/{$sourceBucket}/daily-audio-practice/{$practiceId}/";
        $objectPath = substr($path, strlen("/{$sourceBucket}/"));

        $this->assertSafeSourceObjectPath([
            'path' => $path,
            'prefix' => $prefix,
            'object_path' => $objectPath,
            'track_id' => $trackId,
        ]);

        return $objectPath;
    }

    /**
     * @param  array{audio_url: string, track_id: string}  $source
     * @return array{path: string}
     */
    private function sourceUrlParts(array $source): array
    {
        $parts = $this->parsedSourceUrl($source);

        $origin = [$parts['scheme'] ?? null, $parts['host'] ?? null];

        if ($origin !== ['https', 'storage.googleapis.com']) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$source['track_id']}] has an unsupported GCS URL.",
            );
        }

        $unsupportedParts = array_intersect(
            array_keys($parts),
            ['port', 'user', 'pass', 'query', 'fragment'],
        );

        if ($unsupportedParts !== []) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$source['track_id']}] has an unsupported GCS URL.",
            );
        }

        $path = $parts['path'] ?? null;

        if (! is_string($path)) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$source['track_id']}] has an unsupported GCS URL.",
            );
        }

        return ['path' => $path];
    }

    /**
     * @param  array{audio_url: string, track_id: string}  $source
     * @return array<string, mixed>
     */
    private function parsedSourceUrl(array $source): array
    {
        $parts = parse_url($source['audio_url']);

        if (! is_array($parts)) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$source['track_id']}] has an unsupported GCS URL.",
            );
        }

        return $parts;
    }

    /** @param  array{path: string, prefix: string, object_path: string, track_id: string}  $source */
    private function assertSafeSourceObjectPath(array $source): void
    {
        $unsafe = [
            ! str_starts_with($source['path'], $source['prefix']),
            str_contains($source['path'], '%'),
            str_contains($source['path'], '\\'),
            str_contains($source['path'], "\0"),
            ! str_ends_with(strtolower($source['path']), '.mp3'),
        ];

        if (in_array(true, $unsafe, true)) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$source['track_id']}] has an unsafe GCS object path.",
            );
        }

        foreach (explode('/', $source['object_path']) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new RuntimeException(
                    "Convo Lab Daily Audio track [{$source['track_id']}] has an unsafe GCS object path.",
                );
            }
        }
    }

    private function preflightDestinationFiles(): void
    {
        $disk = Storage::disk((string) config('daily_audio.disk'));

        foreach ($this->tracks as $track) {
            if ($disk->exists($track['destination_path'])) {
                $this->assertDestinationFileMatches($track);
            }
        }
    }

    /**
     * @param  array{destination_path: string, size_bytes: int, checksum_sha256: string}  $track
     */
    private function assertDestinationFileMatches(array $track): void
    {
        $absolutePath = Storage::disk((string) config('daily_audio.disk'))
            ->path($track['destination_path']);
        $size = filesize($absolutePath);
        $checksum = hash_file('sha256', $absolutePath);

        if ($size !== $track['size_bytes'] || $checksum !== $track['checksum_sha256']) {
            throw new RuntimeException(
                "Learning OS Daily Audio file [{$track['destination_path']}] has different bytes.",
            );
        }
    }

    /**
     * @return list<string>
     */
    private function copyMissingFiles(): array
    {
        $disk = Storage::disk((string) config('daily_audio.disk'));
        $created = [];

        try {
            foreach ($this->tracks as $track) {
                $path = $track['destination_path'];

                if ($disk->exists($path)) {
                    continue;
                }

                $stream = fopen($track['source_path'], 'rb');

                if ($stream === false) {
                    throw new RuntimeException("Unable to open source Daily Audio media [{$path}].");
                }

                $created[] = $path;

                try {
                    if (! $disk->put($path, $stream)) {
                        throw new RuntimeException("Unable to write Learning OS Daily Audio media [{$path}].");
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $this->assertDestinationFileMatches($track);
            }
        } catch (Throwable $e) {
            foreach ($created as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return $created;
    }
}
