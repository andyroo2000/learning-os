<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSourceDatabase;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Support\DailyAudioPracticeGeneration;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\ConnectionInterface;
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

            $this->buildTrackManifest($source, $sourceMediaRoot, $sourceBucket);
            $this->assertTargetTracksMatch($target, false);
            $this->preflightDestinationFiles();

            $this->line(sprintf(
                'Verified %d historical Daily Audio tracks.',
                count($this->tracks),
            ));

            $createdPaths = $this->copyMissingFiles();

            $target->transaction(function () use ($target): void {
                $this->assertTargetTracksMatch($target, true);

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

    private function sourceObjectPath(
        string $audioUrl,
        string $sourceBucket,
        string $practiceId,
        string $trackId,
    ): string {
        $parts = parse_url($audioUrl);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'storage.googleapis.com'
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! isset($parts['path'])
            || ! is_string($parts['path'])) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$trackId}] has an unsupported GCS URL.",
            );
        }

        $prefix = "/{$sourceBucket}/daily-audio-practice/{$practiceId}/";
        $path = $parts['path'];

        if (! str_starts_with($path, $prefix)
            || str_contains($path, '%')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || ! str_ends_with(strtolower($path), '.mp3')) {
            throw new RuntimeException(
                "Convo Lab Daily Audio track [{$trackId}] has an unsafe GCS object path.",
            );
        }

        $objectPath = substr($path, strlen("/{$sourceBucket}/"));
        $segments = explode('/', $objectPath);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException(
                    "Convo Lab Daily Audio track [{$trackId}] has an unsafe GCS object path.",
                );
            }
        }

        return $objectPath;
    }

    private function assertTargetTracksMatch(
        ConnectionInterface $target,
        bool $lockForUpdate,
    ): void {
        $legacyQuery = $target->table('daily_audio_practice_tracks')
            ->where('status', 'ready')
            ->whereNotNull('audio_url');
        if ($lockForUpdate) {
            $legacyQuery->lockForUpdate();
        }

        foreach ($legacyQuery->get(['id', 'audio_url']) as $targetTrack) {
            $audioUrl = (string) $targetTrack->audio_url;
            if (! str_starts_with($audioUrl, '/api/daily-audio-practice/')
                && ! isset($this->tracks[strtolower((string) $targetTrack->id)])) {
                throw new RuntimeException(
                    "Learning OS legacy Daily Audio track [{$targetTrack->id}] ".
                    'has no matching Convo Lab source media.',
                );
            }
        }

        if ($this->tracks === []) {
            return;
        }

        $query = $target->table('daily_audio_practice_tracks')
            ->whereIn('id', array_keys($this->tracks));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $targetTracks = $query->get(['id', 'practice_id', 'mode', 'status'])->keyBy('id');

        foreach ($this->tracks as $track) {
            $targetTrack = $targetTracks->get($track['id']);

            if ($targetTrack === null) {
                throw new RuntimeException(
                    "Learning OS has no Daily Audio track matching Convo Lab track [{$track['id']}].",
                );
            }

            if (strtolower((string) $targetTrack->practice_id) !== $track['practice_id']
                || (string) $targetTrack->mode !== $track['mode']
                || (string) $targetTrack->status !== 'ready') {
                throw new RuntimeException(
                    "Learning OS Daily Audio track [{$track['id']}] does not match its ready Convo Lab source.",
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
