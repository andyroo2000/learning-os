<?php

namespace App\Console\Commands;

use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Media\Values\OriginalFilename;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportConvoLabMedia extends Command
{
    private const MAX_SOURCE_KIND_LENGTH = 64;

    private const MAX_SOURCE_METADATA_LENGTH = 255;

    private const LOCK_TTL_SECONDS = 86400;

    private const LOCK_CACHE_STORE = 'database';

    protected $signature = 'migration:import-convolab-media
        {--source-connection=convolab_rehearsal : Temporary source connection name}
        {--source-database= : Restored Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--source-media-root= : Directory containing Convo Lab storage paths exported from GCS}
        {--allow-production : Permit the importer to run when APP_ENV=production}
        {--production-confirmation= : Required production phrase: IMPORT MEDIA INTO <target database>}';

    protected $description = 'Incrementally import verified Convo Lab study media into an existing Learning OS database.';

    /**
     * @var array<string, array{
     *     source_ids: list<string>,
     *     user_id: int,
     *     import_job_id: string|null,
     *     path: string,
     *     source_path: string,
     *     mime_type: string,
     *     size_bytes: int,
     *     checksum_sha256: string,
     *     original_filename: string|null,
     *     source_kind: string|null,
     *     source_media_ref: string|null,
     *     source_filename: string|null,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>
     */
    private array $mediaByPath = [];

    /**
     * @var array<string, string>
     */
    private array $pathBySourceMediaId = [];

    /**
     * @var array<string, int>
     */
    private array $userIdBySourceMediaId = [];

    /**
     * @var array<string, true>
     */
    private array $unavailableSourceMediaIds = [];

    /**
     * @var array<string, true>
     */
    private array $skippedUnavailableCardMediaPairs = [];

    /**
     * @var array<string, array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null
     * }>
     */
    private array $cardsBySourceId = [];

    public function handle(
        RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
    ): int {
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
                    'Another Convo Lab media import is already running for this target database.',
                );
            }

            $source = $this->sourceConnection();
            $this->assertDifferentDatabases($source, $target);
            $this->assertSourceSchema($source);
            $sourceMediaRoot = $this->sourceMediaRoot();

            $this->info('Preflighting Convo Lab study media');

            $userIds = $this->mapSourceUsers($source, $target);
            $importJobIds = $this->mapSourceImportJobs($source, $target);
            $this->cardsBySourceId = $this->mapSourceCards($source, $target, $userIds);
            $this->buildMediaManifest($source, $sourceMediaRoot, $userIds, $importJobIds);
            $cardMediaPairs = $this->buildCardMediaPairs($source);
            $existingMedia = $this->preflightExistingMedia($target);
            $this->preflightDestinationFiles();

            if ($this->unavailableSourceMediaIds !== []) {
                $this->warn(sprintf(
                    'Skipped %d unavailable Convo Lab media rows and %d card media links without storage paths.',
                    count($this->unavailableSourceMediaIds),
                    count($this->skippedUnavailableCardMediaPairs),
                ));
            }

            $this->line(sprintf(
                'Verified %d unique media files and %d card media links.',
                count($this->mediaByPath),
                count($cardMediaPairs),
            ));

            $createdPaths = $this->copyMissingFiles();

            $result = $target->transaction(function () use (
                $target,
                $existingMedia,
                $cardMediaPairs,
                $recordMediaAssetSyncFeedEntry,
                $recordCardMediaSyncFeedEntry,
            ): array {
                $mediaIdsByPath = $this->persistMediaRows(
                    $target,
                    $existingMedia,
                    $recordMediaAssetSyncFeedEntry,
                );
                $createdLinks = $this->persistCardMediaLinks(
                    $target,
                    $cardMediaPairs,
                    $mediaIdsByPath,
                    $recordCardMediaSyncFeedEntry,
                );

                return [
                    'media' => count($mediaIdsByPath),
                    'links' => $createdLinks,
                ];
            });
            $databaseCommitted = true;

            $this->info(sprintf(
                'Convo Lab media import completed: %d media assets, %d new card links.',
                $result['media'],
                $result['links'],
            ));
        } catch (Throwable $e) {
            if (! $databaseCommitted) {
                foreach ($createdPaths as $path) {
                    Storage::disk(MediaAsset::DISK_MEDIA)->delete($path);
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
            'migration:import-convolab-media:'.$target->getDatabaseName(),
            self::LOCK_TTL_SECONDS,
        );
    }

    private function sourceConnection(): ConnectionInterface
    {
        $database = $this->option('source-database');
        $connectionName = trim((string) $this->option('source-connection'));

        if ($connectionName === '') {
            throw new RuntimeException('Source connection name must not be blank.');
        }

        if (! is_string($database) || trim($database) === '') {
            if (config("database.connections.{$connectionName}") !== null) {
                return DB::connection($connectionName);
            }

            throw new RuntimeException('Pass --source-database with the restored Convo Lab source database name.');
        }

        if ($connectionName === DB::getDefaultConnection()) {
            throw new RuntimeException('Source connection name must differ from the target connection name.');
        }

        $targetConfig = config('database.connections.'.DB::getDefaultConnection(), []);
        $sourceConfig = config('database.connections.pgsql');
        $sourceConfig['host'] = $this->option('source-host') ?: ($targetConfig['host'] ?? '127.0.0.1');
        $sourceConfig['port'] = $this->option('source-port') ?: ($targetConfig['port'] ?? '5432');
        $sourceConfig['database'] = trim($database);
        $sourceConfig['username'] = $this->option('source-username') ?: ($targetConfig['username'] ?? null);
        $sourceConfig['password'] = $this->option('source-password') ?? ($targetConfig['password'] ?? null);

        config(["database.connections.{$connectionName}" => $sourceConfig]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    private function assertDifferentDatabases(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): void {
        if ($source->getDatabaseName() === $target->getDatabaseName()
            && $source->getConfig('host') === $target->getConfig('host')
            && (string) $source->getConfig('port') === (string) $target->getConfig('port')) {
            throw new RuntimeException(
                'Source and target databases resolve to the same database. Use a separate restored source copy.',
            );
        }
    }

    private function assertProductionConfirmed(ConnectionInterface $target): void
    {
        if (! app()->isProduction()) {
            return;
        }

        $expected = 'IMPORT MEDIA INTO '.$target->getDatabaseName();

        if ($this->option('production-confirmation') !== $expected) {
            throw new RuntimeException(
                "Production media import requires --production-confirmation=\"{$expected}\".",
            );
        }
    }

    private function assertSourceSchema(ConnectionInterface $source): void
    {
        foreach (['User', 'study_media', 'study_cards'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Source database is missing expected Convo Lab table [{$table}].");
            }
        }
    }

    private function sourceMediaRoot(): string
    {
        $root = $this->option('source-media-root');

        if (! is_string($root) || trim($root) === '') {
            throw new RuntimeException('Pass --source-media-root with the exported Convo Lab media directory.');
        }

        $resolved = realpath($root);

        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("Source media root [{$root}] is not a readable directory.");
        }

        return rtrim($resolved, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, int>
     */
    private function mapSourceUsers(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): array {
        $referencedSourceUserIds = $source->table('study_media')
            ->pluck('userId')
            ->merge(
                $source->table('study_cards')
                    ->where(function ($query): void {
                        $query->whereNotNull('promptAudioMediaId')
                            ->orWhereNotNull('answerAudioMediaId')
                            ->orWhereNotNull('imageMediaId');
                    })
                    ->pluck('userId'),
            )
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();
        $targetUsersByEmail = $target->table('users')
            ->get(['id', 'email'])
            ->mapWithKeys(fn (object $user): array => [
                strtolower(trim((string) $user->email)) => (int) $user->id,
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('User')
            ->whereIn('id', $referencedSourceUserIds)
            ->get(['id', 'email']) as $user) {
            $email = strtolower(trim((string) $user->email));
            $targetUserId = $targetUsersByEmail[$email] ?? null;

            if ($targetUserId === null) {
                throw new RuntimeException("Learning OS has no user matching Convo Lab email [{$user->email}].");
            }

            $mapped[(string) $user->id] = $targetUserId;
        }

        return $mapped;
    }

    /**
     * @return array<string, string>
     */
    private function mapSourceImportJobs(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): array {
        $targetJobs = $target->table('study_import_jobs')
            ->whereNotNull('convolab_id')
            ->pluck('id', 'convolab_id')
            ->mapWithKeys(fn (mixed $id, mixed $sourceId): array => [
                strtolower((string) $sourceId) => (string) $id,
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('study_media')
            ->whereNotNull('importJobId')
            ->pluck('importJobId')
            ->unique() as $sourceId) {
            $normalized = strtolower(trim((string) $sourceId));

            if (! Str::isUuid($normalized)) {
                throw new RuntimeException("Convo Lab import job [{$sourceId}] does not have a valid UUID.");
            }

            if (! isset($targetJobs[$normalized])) {
                throw new RuntimeException(
                    "Learning OS has no import job matching Convo Lab import job [{$sourceId}].",
                );
            }

            $mapped[(string) $sourceId] = $targetJobs[$normalized];
        }

        return $mapped;
    }

    /**
     * @param  array<string, int>  $userIds
     * @return array<string, array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null
     * }>
     */
    private function mapSourceCards(
        ConnectionInterface $source,
        ConnectionInterface $target,
        array $userIds,
    ): array {
        $targetCards = $target->table('cards')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereNotNull('cards.convolab_id')
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->get([
                'cards.id',
                'cards.convolab_id',
                'cards.deck_id',
                'decks.user_id',
                'decks.course_id',
            ])
            ->mapWithKeys(fn (object $card): array => [
                strtolower((string) $card->convolab_id) => [
                    'card_id' => (string) $card->id,
                    'user_id' => (int) $card->user_id,
                    'deck_id' => (string) $card->deck_id,
                    'course_id' => $card->course_id === null ? null : (string) $card->course_id,
                ],
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('study_cards')
            ->where(function ($query): void {
                $query->whereNotNull('promptAudioMediaId')
                    ->orWhereNotNull('answerAudioMediaId')
                    ->orWhereNotNull('imageMediaId');
            })
            ->get(['id', 'userId']) as $sourceCard) {
            $sourceId = strtolower(trim((string) $sourceCard->id));
            $targetCard = $targetCards[$sourceId] ?? null;
            $expectedUserId = $userIds[(string) $sourceCard->userId] ?? null;

            if ($targetCard === null) {
                throw new RuntimeException("Learning OS has no card matching Convo Lab card [{$sourceCard->id}].");
            }

            if ($expectedUserId === null || $targetCard['user_id'] !== $expectedUserId) {
                throw new RuntimeException("Convo Lab card [{$sourceCard->id}] does not match the Learning OS owner.");
            }

            $mapped[(string) $sourceCard->id] = $targetCard;
        }

        return $mapped;
    }

    /**
     * @param  array<string, int>  $userIds
     * @param  array<string, string>  $importJobIds
     */
    private function buildMediaManifest(
        ConnectionInterface $source,
        string $sourceMediaRoot,
        array $userIds,
        array $importJobIds,
    ): void {
        $this->mediaByPath = [];
        $this->pathBySourceMediaId = [];
        $this->userIdBySourceMediaId = [];
        $this->unavailableSourceMediaIds = [];

        $source->table('study_media')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function (Collection $rows) use ($sourceMediaRoot, $userIds, $importJobIds): void {
                foreach ($rows as $media) {
                    $sourceId = (string) $media->id;

                    if (! is_string($media->storagePath) || trim($media->storagePath) === '') {
                        $this->unavailableSourceMediaIds[$sourceId] = true;

                        continue;
                    }

                    $userId = $userIds[(string) $media->userId] ?? null;

                    if ($userId === null) {
                        throw new RuntimeException("Missing Learning OS user mapping for media [{$sourceId}].");
                    }

                    $path = $this->validatedStoragePath($media->storagePath, $sourceId);
                    $sourcePath = $this->resolvedSourcePath($sourceMediaRoot, $path, $sourceId);
                    $size = filesize($sourcePath);
                    $checksum = hash_file('sha256', $sourcePath);

                    if (! is_int($size) || $size < 1 || $size > MediaAsset::MAX_JSON_SAFE_SIZE_BYTES) {
                        throw new RuntimeException("Convo Lab media [{$sourceId}] has an invalid byte size.");
                    }

                    if (! is_string($checksum)) {
                        throw new RuntimeException("Unable to checksum Convo Lab media [{$sourceId}].");
                    }

                    $existing = $this->mediaByPath[$path] ?? null;

                    if ($existing !== null) {
                        if ($existing['user_id'] !== $userId) {
                            throw new RuntimeException("Media path [{$path}] is shared by multiple Convo Lab users.");
                        }

                        if ($existing['size_bytes'] !== $size || $existing['checksum_sha256'] !== $checksum) {
                            throw new RuntimeException("Media path [{$path}] resolves to inconsistent source bytes.");
                        }

                        $this->mediaByPath[$path]['source_ids'][] = $sourceId;
                    } else {
                        $sourceImportJobId = is_string($media->importJobId) ? $media->importJobId : null;
                        $this->mediaByPath[$path] = [
                            'source_ids' => [$sourceId],
                            'user_id' => $userId,
                            'import_job_id' => $sourceImportJobId === null
                                ? null
                                : ($importJobIds[$sourceImportJobId] ?? null),
                            'path' => $path,
                            'source_path' => $sourcePath,
                            'mime_type' => $this->boundedStringOrDefault(
                                $media->contentType,
                                'application/octet-stream',
                                MediaAsset::MAX_MIME_TYPE_LENGTH,
                                'content type',
                                $sourceId,
                            ),
                            'size_bytes' => $size,
                            'checksum_sha256' => $checksum,
                            'original_filename' => $this->boundedNullableString(
                                OriginalFilename::normalize(
                                    is_string($media->sourceFilename) ? $media->sourceFilename : null,
                                ),
                                MediaAsset::MAX_ORIGINAL_FILENAME_LENGTH,
                                'original filename',
                                $sourceId,
                            ),
                            'source_kind' => $this->boundedNullableString(
                                $media->sourceKind,
                                self::MAX_SOURCE_KIND_LENGTH,
                                'source kind',
                                $sourceId,
                            ),
                            'source_media_ref' => $this->boundedNullableString(
                                $media->sourceMediaKey,
                                self::MAX_SOURCE_METADATA_LENGTH,
                                'source media reference',
                                $sourceId,
                            ),
                            'source_filename' => $this->boundedNullableString(
                                $media->sourceFilename,
                                self::MAX_SOURCE_METADATA_LENGTH,
                                'source filename',
                                $sourceId,
                            ),
                            'created_at' => $media->createdAt,
                            'updated_at' => $media->updatedAt,
                        ];
                    }

                    $this->pathBySourceMediaId[$sourceId] = $path;
                    $this->userIdBySourceMediaId[$sourceId] = $userId;
                }
            });
    }

    private function validatedStoragePath(mixed $value, string $sourceId): string
    {
        $path = is_string($value) ? trim(str_replace('\\', '/', $value)) : '';
        $normalized = ltrim($path, '/');

        if ($path === ''
            || $normalized !== $path
            || str_contains($normalized, "\0")
            || preg_match(MediaAsset::PATH_ABSOLUTE_PATTERN, $normalized) === 1
            || preg_match(MediaAsset::PATH_TRAVERSAL_PATTERN, $normalized) === 1
            || ! str_starts_with($normalized, 'study-media/')
            || strlen($normalized) > MediaAsset::MAX_PATH_LENGTH) {
            throw new RuntimeException("Convo Lab media [{$sourceId}] has an unsafe storage path.");
        }

        return $normalized;
    }

    private function resolvedSourcePath(string $root, string $path, string $sourceId): string
    {
        $candidate = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path));
        $prefix = $root.DIRECTORY_SEPARATOR;

        if ($candidate === false
            || ! is_file($candidate)
            || (! str_starts_with($candidate, $prefix) && $candidate !== $root)) {
            throw new RuntimeException("Convo Lab media bytes are missing for [{$sourceId}] at [{$path}].");
        }

        return $candidate;
    }

    /**
     * @return list<array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null,
     *     path: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>
     */
    private function buildCardMediaPairs(ConnectionInterface $source): array
    {
        $pairs = [];
        $this->skippedUnavailableCardMediaPairs = [];

        foreach ($source->table('study_cards')
            ->where(function ($query): void {
                $query->whereNotNull('promptAudioMediaId')
                    ->orWhereNotNull('answerAudioMediaId')
                    ->orWhereNotNull('imageMediaId');
            })
            ->get(['id', 'userId', 'promptAudioMediaId', 'answerAudioMediaId', 'imageMediaId', 'createdAt', 'updatedAt']) as $card) {
            $targetCard = $this->cardsBySourceId[(string) $card->id];

            foreach ([$card->promptAudioMediaId, $card->answerAudioMediaId, $card->imageMediaId] as $sourceMediaId) {
                if ($sourceMediaId === null || $sourceMediaId === '') {
                    continue;
                }

                $sourceMediaId = (string) $sourceMediaId;
                if (isset($this->unavailableSourceMediaIds[$sourceMediaId])) {
                    $this->skippedUnavailableCardMediaPairs[
                        (string) $card->id."\n".$sourceMediaId
                    ] = true;

                    continue;
                }

                $path = $this->pathBySourceMediaId[$sourceMediaId] ?? null;

                if ($path === null) {
                    throw new RuntimeException("Missing imported media mapping for [{$sourceMediaId}].");
                }

                if (($this->userIdBySourceMediaId[$sourceMediaId] ?? null) !== $targetCard['user_id']) {
                    throw new RuntimeException(
                        "Card [{$card->id}] references media [{$sourceMediaId}] owned by another user.",
                    );
                }

                $key = $targetCard['card_id']."\n".$path;
                $pairs[$key] = [
                    'card_id' => $targetCard['card_id'],
                    'user_id' => $targetCard['user_id'],
                    'deck_id' => $targetCard['deck_id'],
                    'course_id' => $targetCard['course_id'],
                    'path' => $path,
                    'created_at' => $card->createdAt,
                    'updated_at' => $card->updatedAt,
                ];
            }
        }

        return array_values($pairs);
    }

    /**
     * @return array<string, object>
     */
    private function preflightExistingMedia(ConnectionInterface $target): array
    {
        if ($this->mediaByPath === []) {
            return [];
        }

        $existing = $target->table('media_assets')
            ->where('disk', MediaAsset::DISK_MEDIA)
            ->whereIn('path', array_keys($this->mediaByPath))
            ->get()
            ->keyBy('path')
            ->all();

        foreach ($existing as $path => $row) {
            $manifest = $this->mediaByPath[$path];

            if ((int) $row->user_id !== $manifest['user_id']) {
                throw new RuntimeException("Learning OS media path [{$path}] belongs to another user.");
            }

            $size = (int) $row->size_bytes;
            $checksum = is_string($row->checksum_sha256) ? strtolower($row->checksum_sha256) : null;
            $isMetadataOnly = $size === 0 && $checksum === null;

            if (! $isMetadataOnly
                && ($size !== $manifest['size_bytes'] || $checksum !== $manifest['checksum_sha256'])) {
                throw new RuntimeException("Learning OS media path [{$path}] has different verified bytes.");
            }
        }

        return $existing;
    }

    private function preflightDestinationFiles(): void
    {
        $disk = Storage::disk(MediaAsset::DISK_MEDIA);

        foreach ($this->mediaByPath as $path => $manifest) {
            if (! $disk->exists($path)) {
                continue;
            }

            $this->assertDestinationFileMatches($path, $manifest);
        }
    }

    /**
     * @param  array{size_bytes: int, checksum_sha256: string}  $manifest
     */
    private function assertDestinationFileMatches(string $path, array $manifest): void
    {
        $absolutePath = Storage::disk(MediaAsset::DISK_MEDIA)->path($path);
        $size = filesize($absolutePath);
        $checksum = hash_file('sha256', $absolutePath);

        if ($size !== $manifest['size_bytes'] || $checksum !== $manifest['checksum_sha256']) {
            throw new RuntimeException("Learning OS media file [{$path}] has different bytes.");
        }
    }

    /**
     * @return list<string>
     */
    private function copyMissingFiles(): array
    {
        $disk = Storage::disk(MediaAsset::DISK_MEDIA);
        $created = [];

        try {
            foreach ($this->mediaByPath as $path => $manifest) {
                if ($disk->exists($path)) {
                    continue;
                }

                $stream = fopen($manifest['source_path'], 'rb');

                if ($stream === false) {
                    throw new RuntimeException("Unable to open source media [{$path}].");
                }

                // Register the path first so a partial or failed write is also cleaned up.
                $created[] = $path;

                try {
                    if (! $disk->put($path, $stream)) {
                        throw new RuntimeException("Unable to write Learning OS media [{$path}].");
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $this->assertDestinationFileMatches($path, $manifest);
            }
        } catch (Throwable $e) {
            foreach ($created as $path) {
                $disk->delete($path);
            }

            throw $e;
        }

        return $created;
    }

    /**
     * @param  array<string, object>  $existing
     * @return array<string, string>
     */
    private function persistMediaRows(
        ConnectionInterface $target,
        array $existing,
        RecordMediaAssetSyncFeedEntryAction $recordSyncFeedEntry,
    ): array {
        $ids = [];
        $existingResourceUserIds = [];

        foreach ($existing as $row) {
            $existingResourceUserIds[(string) $row->id] = (int) $row->user_id;
        }

        $existingFeedResourceIds = $this->existingCreateSyncResourceIds(
            $target,
            MediaAssetSyncPayload::RESOURCE_TYPE,
            $existingResourceUserIds,
        );

        foreach ($this->mediaByPath as $path => $manifest) {
            $row = $existing[$path] ?? null;

            if ($row !== null) {
                $wasVerified = ! ((int) $row->size_bytes === 0 && $row->checksum_sha256 === null);

                if (! $wasVerified) {
                    $target->table('media_assets')->where('id', $row->id)->update([
                        'size_bytes' => $manifest['size_bytes'],
                        'checksum_sha256' => $manifest['checksum_sha256'],
                        'mime_type' => $manifest['mime_type'],
                        'updated_at' => $manifest['updated_at'],
                    ]);
                }

                $existingId = (string) $row->id;

                if (! isset($existingFeedResourceIds[$existingId])) {
                    $this->recordMediaSyncEntry(
                        $existingId,
                        $manifest['user_id'],
                        $recordSyncFeedEntry,
                    );
                }

                $ids[$path] = $existingId;

                continue;
            }

            $id = CanonicalUlid::normalize((string) Str::ulid());
            $target->table('media_assets')->insert([
                'id' => $id,
                'user_id' => $manifest['user_id'],
                'import_job_id' => $manifest['import_job_id'],
                'disk' => MediaAsset::DISK_MEDIA,
                'path' => $path,
                'public_url' => null,
                'mime_type' => $manifest['mime_type'],
                'size_bytes' => $manifest['size_bytes'],
                'checksum_sha256' => $manifest['checksum_sha256'],
                'original_filename' => $manifest['original_filename'],
                'source_kind' => $manifest['source_kind'],
                'source_media_ref' => $manifest['source_media_ref'],
                'source_filename' => $manifest['source_filename'],
                'created_at' => $manifest['created_at'],
                'updated_at' => $manifest['updated_at'],
            ]);
            $this->recordMediaSyncEntry($id, $manifest['user_id'], $recordSyncFeedEntry);
            $ids[$path] = $id;
        }

        return $ids;
    }

    /**
     * @param  list<array{
     *     card_id: string,
     *     user_id: int,
     *     deck_id: string,
     *     course_id: string|null,
     *     path: string,
     *     created_at: mixed,
     *     updated_at: mixed
     * }>  $pairs
     * @param  array<string, string>  $mediaIdsByPath
     */
    private function persistCardMediaLinks(
        ConnectionInterface $target,
        array $pairs,
        array $mediaIdsByPath,
        RecordCardMediaSyncFeedEntryAction $recordSyncFeedEntry,
    ): int {
        $created = 0;
        $resourceIdsByPair = [];
        $resourceUserIds = [];

        foreach ($pairs as $key => $pair) {
            $resourceIdsByPair[$key] = CardMediaSyncPayload::resourceId(
                $pair['card_id'],
                $mediaIdsByPath[$pair['path']],
            );
            $resourceUserIds[$resourceIdsByPair[$key]] = $pair['user_id'];
        }

        $existingFeedResourceIds = $this->existingCreateSyncResourceIds(
            $target,
            CardMediaSyncPayload::RESOURCE_TYPE,
            $resourceUserIds,
        );

        foreach ($pairs as $key => $pair) {
            $mediaAssetId = $mediaIdsByPath[$pair['path']];
            $resourceId = $resourceIdsByPair[$key];
            $inserted = $target->table('card_media')->insertOrIgnore([
                'card_id' => $pair['card_id'],
                'media_asset_id' => $mediaAssetId,
                'created_at' => $pair['created_at'],
                'updated_at' => $pair['updated_at'],
            ]);

            if ($inserted === 1 || ! isset($existingFeedResourceIds[$resourceId])) {
                $recordSyncFeedEntry->handle(
                    userId: $pair['user_id'],
                    operation: SyncFeedOperation::Create,
                    cardId: $pair['card_id'],
                    mediaAssetId: $mediaAssetId,
                    deckId: $pair['deck_id'],
                    courseId: $pair['course_id'],
                    createdAt: $pair['created_at'],
                    updatedAt: $pair['updated_at'],
                );
            }

            if ($inserted === 1) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, int>  $resourceUserIds
     * @return array<string, true>
     */
    private function existingCreateSyncResourceIds(
        ConnectionInterface $target,
        string $resourceType,
        array $resourceUserIds,
    ): array {
        $existing = [];
        $resourceIdsByUser = [];

        foreach ($resourceUserIds as $resourceId => $userId) {
            $resourceIdsByUser[$userId][] = $resourceId;
        }

        foreach ($resourceIdsByUser as $userId => $resourceIds) {
            foreach (array_chunk(array_values(array_unique($resourceIds)), 500) as $chunk) {
                foreach ($target->table('sync_feed_entries')
                    ->where('user_id', $userId)
                    ->where('domain', MediaAssetSyncPayload::DOMAIN)
                    ->where('resource_type', $resourceType)
                    ->where('operation', SyncFeedOperation::Create->value)
                    ->whereIn('resource_id', $chunk)
                    ->pluck('resource_id') as $resourceId) {
                    $existing[(string) $resourceId] = true;
                }
            }
        }

        return $existing;
    }

    private function recordMediaSyncEntry(
        string $mediaAssetId,
        int $userId,
        RecordMediaAssetSyncFeedEntryAction $recordSyncFeedEntry,
    ): void {
        $mediaAsset = MediaAsset::query()->findOrFail($mediaAssetId);

        $recordSyncFeedEntry->handle(
            userId: $userId,
            operation: SyncFeedOperation::Create,
            mediaAsset: $mediaAsset,
        );
    }

    private function boundedNullableString(
        mixed $value,
        int $maxLength,
        string $label,
        string $sourceId,
    ): ?string {
        $normalized = is_string($value) && trim($value) !== '' ? trim($value) : null;

        if ($normalized !== null && mb_strlen($normalized) > $maxLength) {
            throw new RuntimeException(
                "Convo Lab media [{$sourceId}] {$label} exceeds {$maxLength} characters.",
            );
        }

        return $normalized;
    }

    private function boundedStringOrDefault(
        mixed $value,
        string $default,
        int $maxLength,
        string $label,
        string $sourceId,
    ): string {
        return $this->boundedNullableString($value, $maxLength, $label, $sourceId) ?? $default;
    }
}
