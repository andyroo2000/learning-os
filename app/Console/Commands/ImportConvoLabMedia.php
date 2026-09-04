<?php

namespace App\Console\Commands;

use App\Console\Concerns\BuildsConvoLabMediaImportManifest;
use App\Console\Concerns\ConnectsToConvoLabSourceDatabase;
use App\Console\Support\ConvoLabMediaImportMapper;
use App\Console\Support\ConvoLabMediaImportState;
use App\Domain\Media\Actions\RecordCardMediaSyncFeedEntryAction;
use App\Domain\Media\Actions\RecordMediaAssetSyncFeedEntryAction;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Sync\CardMediaSyncPayload;
use App\Domain\Media\Sync\MediaAssetSyncPayload;
use App\Domain\Study\Actions\RepairLegacyStudyMediaReferencesAction;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportConvoLabMedia extends Command
{
    use BuildsConvoLabMediaImportManifest;
    use ConnectsToConvoLabSourceDatabase;

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

    public function __construct(private readonly ConvoLabMediaImportMapper $importMapper)
    {
        parent::__construct();
    }

    public function handle(
        RecordMediaAssetSyncFeedEntryAction $recordMediaAssetSyncFeedEntry,
        RecordCardMediaSyncFeedEntryAction $recordCardMediaSyncFeedEntry,
        RepairLegacyStudyMediaReferencesAction $repairLegacyStudyMediaReferences,
    ): int {
        if ($this->productionOverrideMissing()) {
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
            $this->acquireImportLock($lock);
            $lockAcquired = true;

            $source = $this->convoLabSourceConnection();
            $this->assertConvoLabSourceDiffersFromTarget($source, $target);
            $this->assertSourceSchema($source);
            $sourceMediaRoot = $this->convoLabSourceMediaRoot();
            $preflight = $this->preflightMedia($source, $target, $sourceMediaRoot);
            $cardMediaPairs = $preflight['card_media_pairs'];
            $existingMedia = $preflight['existing_media'];

            $createdPaths = $this->copyMissingFiles();

            $result = $target->transaction(function () use (
                $target,
                $existingMedia,
                $cardMediaPairs,
                $recordMediaAssetSyncFeedEntry,
                $recordCardMediaSyncFeedEntry,
                $repairLegacyStudyMediaReferences,
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
                $repairResult = $repairLegacyStudyMediaReferences->handle(
                    $target,
                    apply: true,
                    cardIds: array_values(array_unique(array_column($cardMediaPairs, 'card_id'))),
                );

                return [
                    'media' => count($mediaIdsByPath),
                    'links' => $createdLinks,
                    'repaired_references' => $repairResult->referencesChanged,
                    'repaired_cards' => $repairResult->cardsChanged,
                ];
            });
            $databaseCommitted = true;

            $this->reportCompletedImport($result);
        } catch (Throwable $e) {
            $this->cleanupFailedImport($createdPaths, $databaseCommitted);

            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->releaseImportLock($lock, $lockAcquired);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     card_media_pairs: list<array{
     *         card_id: string,
     *         user_id: int,
     *         deck_id: string,
     *         course_id: string|null,
     *         path: string,
     *         created_at: mixed,
     *         updated_at: mixed
     *     }>,
     *     existing_media: array<string, object>
     * }
     */
    private function preflightMedia(
        ConnectionInterface $source,
        ConnectionInterface $target,
        string $sourceMediaRoot,
    ): array {
        $this->info('Preflighting Convo Lab study media');

        $this->importState = new ConvoLabMediaImportState;
        $mappings = $this->importMapper->map($source, $target);
        $this->importState->cardsBySourceId = $mappings->cardsBySourceId;
        $this->buildMediaManifest($source, $sourceMediaRoot, $mappings);
        $cardMediaPairs = $this->buildCardMediaPairs($source);
        $existingMedia = $this->preflightExistingMedia($target);
        $this->preflightDestinationFiles();

        $this->reportUnavailableMedia();
        $this->line(sprintf(
            'Verified %d unique media files and %d card media links.',
            count($this->importState->mediaByPath),
            count($cardMediaPairs),
        ));

        return [
            'card_media_pairs' => $cardMediaPairs,
            'existing_media' => $existingMedia,
        ];
    }

    private function productionOverrideMissing(): bool
    {
        return app()->isProduction() && ! $this->option('allow-production');
    }

    private function acquireImportLock(Lock $lock): void
    {
        if (! $lock->get()) {
            throw new RuntimeException(
                'Another Convo Lab media import is already running for this target database.',
            );
        }
    }

    private function reportUnavailableMedia(): void
    {
        if ($this->importState->unavailableSourceMediaIds === []) {
            return;
        }

        $this->warn(sprintf(
            'Skipped %d unavailable Convo Lab media rows and %d card media links without storage paths.',
            count($this->importState->unavailableSourceMediaIds),
            count($this->importState->skippedUnavailableCardMediaPairs),
        ));
    }

    /**
     * @param  array{media: int, links: int, repaired_references: int, repaired_cards: int}  $result
     */
    private function reportCompletedImport(array $result): void
    {
        $this->info(sprintf(
            'Convo Lab media import completed: %d media assets, %d new card links.',
            $result['media'],
            $result['links'],
        ));
        $this->line(sprintf(
            'Repaired %d legacy media references across %d cards.',
            $result['repaired_references'],
            $result['repaired_cards'],
        ));
    }

    /** @param  list<string>  $createdPaths */
    private function cleanupFailedImport(array $createdPaths, bool $databaseCommitted): void
    {
        if ($databaseCommitted) {
            return;
        }

        foreach ($createdPaths as $path) {
            Storage::disk(MediaAsset::DISK_MEDIA)->delete($path);
        }
    }

    private function releaseImportLock(?Lock $lock, bool $lockAcquired): void
    {
        if ($lockAcquired) {
            $lock?->release();
        }
    }

    private function importLock(ConnectionInterface $target): Lock
    {
        return Cache::store(self::LOCK_CACHE_STORE)->lock(
            'migration:import-convolab-media:'.$target->getDatabaseName(),
            self::LOCK_TTL_SECONDS,
        );
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

    /**
     * @return array<string, object>
     */
    private function preflightExistingMedia(ConnectionInterface $target): array
    {
        if ($this->importState->mediaByPath === []) {
            return [];
        }

        $existing = $target->table('media_assets')
            ->where('disk', MediaAsset::DISK_MEDIA)
            ->whereIn('path', array_keys($this->importState->mediaByPath))
            ->get()
            ->keyBy('path')
            ->all();

        foreach ($existing as $path => $row) {
            $this->assertExistingMediaMatches($path, $row, $this->importState->mediaByPath[$path]);
        }

        return $existing;
    }

    /** @param  array{user_id: int, size_bytes: int, checksum_sha256: string}  $manifest */
    private function assertExistingMediaMatches(string $path, object $row, array $manifest): void
    {
        if ((int) $row->user_id !== $manifest['user_id']) {
            throw new RuntimeException("Learning OS media path [{$path}] belongs to another user.");
        }

        if ($this->existingMediaHasDifferentVerifiedBytes($row, $manifest)) {
            throw new RuntimeException("Learning OS media path [{$path}] has different verified bytes.");
        }
    }

    /** @param  array{size_bytes: int, checksum_sha256: string}  $manifest */
    private function existingMediaHasDifferentVerifiedBytes(object $row, array $manifest): bool
    {
        $size = (int) $row->size_bytes;
        $checksum = is_string($row->checksum_sha256) ? strtolower($row->checksum_sha256) : null;

        if ($size === 0 && $checksum === null) {
            return false;
        }

        return $size !== $manifest['size_bytes'] || $checksum !== $manifest['checksum_sha256'];
    }

    private function preflightDestinationFiles(): void
    {
        $disk = Storage::disk(MediaAsset::DISK_MEDIA);

        foreach ($this->importState->mediaByPath as $path => $manifest) {
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
            foreach ($this->importState->mediaByPath as $path => $manifest) {
                $this->copyAndTrackFileIfMissing($disk, $path, $manifest, $created);
            }
        } catch (Throwable $e) {
            $this->deleteCreatedFiles($disk, $created);

            throw $e;
        }

        return $created;
    }

    /**
     * @param  array{source_path: string, size_bytes: int, checksum_sha256: string}  $manifest
     * @param  list<string>  $created
     */
    private function copyAndTrackFileIfMissing(
        FilesystemAdapter $disk,
        string $path,
        array $manifest,
        array &$created,
    ): void {
        if ($disk->exists($path)) {
            return;
        }

        $stream = fopen($manifest['source_path'], 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open source media [{$path}].");
        }

        // Register the path first so a partial or failed write is also cleaned up.
        $created[] = $path;
        $this->writeFileFromStream($disk, $path, $stream);
        $this->assertDestinationFileMatches($path, $manifest);
    }

    /** @param  resource  $stream */
    private function writeFileFromStream(FilesystemAdapter $disk, string $path, mixed $stream): void
    {
        try {
            if (! $disk->put($path, $stream)) {
                throw new RuntimeException("Unable to write Learning OS media [{$path}].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param  list<string>  $created */
    private function deleteCreatedFiles(FilesystemAdapter $disk, array $created): void
    {
        foreach ($created as $path) {
            $disk->delete($path);
        }
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

        foreach ($this->importState->mediaByPath as $path => $manifest) {
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
}
