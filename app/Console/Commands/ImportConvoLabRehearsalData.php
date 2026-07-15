<?php

namespace App\Console\Commands;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Enums\StudyImportStatus;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class ImportConvoLabRehearsalData extends Command
{
    /**
     * Complete target reset boundary, including tables reached through user/deck/card foreign keys.
     *
     * @var list<string>
     */
    private const TARGET_TABLES = [
        'card_media',
        'card_review_events',
        'sync_feed_entries',
        'study_card_drafts',
        'media_assets',
        'cards',
        'decks',
        'courses',
        'study_import_jobs',
        'study_settings',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'users',
    ];

    protected $signature = 'rehearsal:import-convolab
        {--source-connection=convolab_rehearsal : Temporary source connection name}
        {--source-database= : Restored Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--truncate : Delete existing Learning OS study data before importing}
        {--allow-production : Permit the importer to run when APP_ENV=production}';

    protected $description = 'Import read-oriented Convo Lab study data into a migrated Learning OS rehearsal database.';

    /**
     * @var array<string, int>
     */
    private array $userIds = [];

    /**
     * @var array<string, string>
     */
    private array $deckIds = [];

    /**
     * @var array<string, string>
     */
    private array $importJobIds = [];

    /**
     * @var array<string, string>
     */
    private array $cardIds = [];

    /**
     * @var array<string, string>
     */
    private array $mediaIds = [];

    /**
     * @var array<string, string>
     */
    private array $mediaPathIds = [];

    /**
     * @var array<string, int>
     */
    private array $mediaPathUserIds = [];

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('This command must not run in production without --allow-production.');

            return self::FAILURE;
        }

        $this->resetMappings();

        try {
            $target = DB::connection();
            $source = $this->sourceConnection();

            if ($this->sameDatabase($source, $target)) {
                throw new \RuntimeException(
                    'Source and target databases resolve to the same database. Use a separate restored source copy.',
                );
            }

            $this->assertConvoLabSource($source);
            $this->assertTargetIsReady($target);

            $this->info('Importing Convo Lab rehearsal data');

            $target->transaction(function () use ($source, $target): void {
                if ($this->option('truncate')) {
                    $this->truncateTarget($target);
                }

                $this->importUsers($source, $target);
                $this->importDecks($source, $target);
                $this->importStudySettings($source, $target);
                $this->importStudyImportJobs($source, $target);
                $this->importMedia($source, $target);
                $this->importCards($source, $target);
                $this->importCardMedia($source, $target);
                $this->importReviewLogs($source, $target);
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Convo Lab rehearsal import completed.');

        return self::SUCCESS;
    }

    private function sourceConnection(): ConnectionInterface
    {
        $database = $this->option('source-database');
        $connectionName = trim((string) $this->option('source-connection'));

        if ($connectionName === '') {
            throw new \InvalidArgumentException('Source connection name must not be blank.');
        }

        if (! is_string($database) || trim($database) === '') {
            if (config("database.connections.{$connectionName}") !== null) {
                return DB::connection($connectionName);
            }

            throw new \InvalidArgumentException('Pass --source-database with the restored Convo Lab source database name.');
        }

        if ($connectionName === DB::getDefaultConnection()) {
            throw new \InvalidArgumentException('Source connection name must differ from the target connection name.');
        }

        $targetConfig = config('database.connections.'.DB::getDefaultConnection(), []);
        $sourceConfig = config('database.connections.pgsql');
        $sourceConfig['host'] = $this->option('source-host') ?: ($targetConfig['host'] ?? '127.0.0.1');
        $sourceConfig['port'] = $this->option('source-port') ?: ($targetConfig['port'] ?? '5432');
        $sourceConfig['database'] = trim($database);
        // A blank username means "reuse the target role"; a blank password is meaningful for trust auth.
        $sourceConfig['username'] = $this->option('source-username') ?: ($targetConfig['username'] ?? null);
        $sourceConfig['password'] = $this->option('source-password') ?? ($targetConfig['password'] ?? null);

        config(["database.connections.{$connectionName}" => $sourceConfig]);
        DB::purge($connectionName);

        return DB::connection($connectionName);
    }

    private function resetMappings(): void
    {
        $this->userIds = [];
        $this->deckIds = [];
        $this->importJobIds = [];
        $this->cardIds = [];
        $this->mediaIds = [];
        $this->mediaPathIds = [];
        $this->mediaPathUserIds = [];
    }

    private function sameDatabase(ConnectionInterface $source, ConnectionInterface $target): bool
    {
        return $source->getDatabaseName() === $target->getDatabaseName()
            && $source->getConfig('host') === $target->getConfig('host')
            && (string) $source->getConfig('port') === (string) $target->getConfig('port');
    }

    private function assertConvoLabSource(ConnectionInterface $source): void
    {
        foreach (['User', 'study_cards', 'study_review_logs', 'study_media', 'study_import_jobs', 'study_settings'] as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException("Source database is missing expected Convo Lab table [{$table}].");
            }
        }
    }

    private function assertTargetIsReady(ConnectionInterface $target): void
    {
        if ($this->option('truncate')) {
            return;
        }

        foreach (self::TARGET_TABLES as $table) {
            if ($target->table($table)->exists()) {
                throw new \RuntimeException(
                    "Learning OS target table [{$table}] is not empty. Re-run with --truncate against a disposable target.",
                );
            }
        }
    }

    private function truncateTarget(ConnectionInterface $target): void
    {
        $driver = $target->getDriverName();

        if ($driver === 'pgsql') {
            $tables = implode(', ', array_map(
                static fn (string $table): string => '"'.$table.'"',
                self::TARGET_TABLES,
            ));
            $target->statement("TRUNCATE TABLE {$tables} RESTART IDENTITY");

            return;
        }

        foreach (self::TARGET_TABLES as $table) {
            $target->table($table)->delete();
        }
    }

    private function importUsers(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('User')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($users) use ($target, &$count): void {
                foreach ($users as $user) {
                    $existingId = $target->table('users')->where('email', $user->email)->value('id');
                    // ConvoLab and Learning OS currently share Laravel-compatible password hashes.
                    $password = is_string($user->password) && $user->password !== ''
                        ? $user->password
                        : Hash::make(Str::random(32));

                    if ($existingId === null) {
                        $existingId = $target->table('users')->insertGetId([
                            'name' => $user->displayName ?: $user->name ?: $user->email,
                            'email' => $user->email,
                            'email_verified_at' => $user->emailVerifiedAt,
                            'password' => $password,
                            'remember_token' => null,
                            'created_at' => $user->createdAt,
                            'updated_at' => $user->updatedAt,
                        ]);
                    }

                    $this->userIds[$user->id] = (int) $existingId;
                    $count++;
                }
            });

        $this->line("Imported {$count} users.");
    }

    private function importDecks(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $rows = $source->table('study_cards')
            ->select('userId', 'sourceDeckName')
            ->distinct()
            ->orderBy('userId')
            ->orderBy('sourceDeckName')
            ->get();

        $now = now();

        $count = 0;

        foreach ($rows as $row) {
            $userId = $this->mappedUserId($row->userId);
            $deckName = $this->stringOrDefault($row->sourceDeckName, 'Convo Lab Study Cards');
            $key = $this->deckKey($row->userId, $deckName);

            if (isset($this->deckIds[$key])) {
                continue;
            }

            $deckId = (string) Str::ulid();

            $target->table('decks')->insert([
                'id' => $deckId,
                'user_id' => $userId,
                'course_id' => null,
                'name' => $deckName,
                'description' => 'Imported from a Convo Lab rehearsal database copy.',
                'is_manual_study_deck' => false,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->deckIds[$key] = $deckId;
            $count++;
        }

        $this->line("Imported {$count} decks.");
    }

    private function importStudySettings(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('study_settings')
            ->orderBy('userId')
            ->chunk(200, function ($settingsRows) use ($target, &$count): void {
                foreach ($settingsRows as $settings) {
                    $target->table('study_settings')->insert([
                        'user_id' => $this->mappedUserId($settings->userId),
                        'new_cards_per_day' => $settings->newCardsPerDay,
                        'created_at' => $settings->createdAt,
                        'updated_at' => $settings->updatedAt,
                    ]);

                    $count++;
                }
            });

        $this->line("Imported {$count} study settings rows.");
    }

    private function importStudyImportJobs(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('study_import_jobs')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($jobs) use ($target, &$count): void {
                foreach ($jobs as $job) {
                    $id = (string) Str::ulid();
                    $this->importJobIds[$job->id] = $id;
                    $status = StudyImportStatus::tryFrom($job->status)
                        ?? throw new \RuntimeException("Unsupported Convo Lab import status [{$job->status}].");

                    $target->table('study_import_jobs')->insert([
                        'id' => $id,
                        'user_id' => $this->mappedUserId($job->userId),
                        'status' => $status->value,
                        'source_type' => $this->stringOrDefault($job->sourceType, 'anki_colpkg'),
                        'source_filename' => $this->stringOrDefault($job->sourceFilename, 'convo-lab-import.colpkg'),
                        'source_object_path' => $job->sourceObjectPath,
                        'source_content_type' => $job->sourceContentType,
                        'source_size_bytes' => $job->sourceSizeBytes,
                        'deck_name' => $this->stringOrDefault($job->deckName, 'Japanese'),
                        'preview_json' => $job->previewJson,
                        'summary_json' => $job->summaryJson,
                        'error_message' => $job->errorMessage,
                        'started_at' => $job->startedAt,
                        'uploaded_at' => $job->uploadedAt,
                        'upload_completed_at' => $job->uploadedAt,
                        'upload_expires_at' => $job->uploadExpiresAt,
                        'completed_at' => $job->completedAt,
                        'created_at' => $job->createdAt,
                        'updated_at' => $job->updatedAt,
                    ]);

                    $count++;
                }
            });

        $this->line("Imported {$count} study import jobs.");
    }

    private function importMedia(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;
        $deduped = 0;

        $source->table('study_media')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function ($mediaRows) use ($target, &$count, &$deduped): void {
                $insertRows = [];

                foreach ($mediaRows as $media) {
                    $path = $this->stringOrDefault($media->storagePath, 'convolab-missing/'.$media->id);
                    $pathKey = MediaAsset::DISK_MEDIA."\n".$path;
                    $userId = $this->mappedUserId($media->userId);

                    if (isset($this->mediaPathIds[$pathKey])) {
                        if ($this->mediaPathUserIds[$pathKey] !== $userId) {
                            throw new \RuntimeException("Media path [{$path}] is shared by multiple Convo Lab users.");
                        }

                        $this->mediaIds[$media->id] = $this->mediaPathIds[$pathKey];
                        $deduped++;

                        continue;
                    }

                    $id = (string) Str::ulid();
                    $this->mediaIds[$media->id] = $id;
                    $this->mediaPathIds[$pathKey] = $id;
                    $this->mediaPathUserIds[$pathKey] = $userId;

                    $insertRows[] = [
                        'id' => $id,
                        'user_id' => $userId,
                        'import_job_id' => $this->mappedImportJobId($media->importJobId),
                        'disk' => MediaAsset::DISK_MEDIA,
                        'path' => $path,
                        'public_url' => $media->publicUrl,
                        'mime_type' => $this->stringOrDefault($media->contentType, 'application/octet-stream'),
                        // The rehearsal imports metadata only; media bytes are copied in a later rollout step.
                        'size_bytes' => 0,
                        'checksum_sha256' => null,
                        'original_filename' => $media->sourceFilename,
                        'source_kind' => $media->sourceKind,
                        'source_media_ref' => $media->sourceMediaKey,
                        'source_filename' => $media->sourceFilename,
                        'created_at' => $media->createdAt,
                        'updated_at' => $media->updatedAt,
                    ];
                }

                if ($insertRows !== []) {
                    $target->table('media_assets')->insert($insertRows);
                }

                $count += count($insertRows);
            });

        $this->line("Imported {$count} media assets ({$deduped} duplicate source paths reused).");
    }

    private function importCards(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('study_cards')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(500, function ($cards) use ($target, &$count): void {
                $insertRows = [];

                foreach ($cards as $card) {
                    $id = (string) Str::ulid();
                    $this->cardIds[$card->id] = $id;
                    $deckName = $this->stringOrDefault($card->sourceDeckName, 'Convo Lab Study Cards');
                    $promptText = $this->payloadText($card->promptJson);
                    $answerText = $this->payloadText($card->answerJson);
                    $cardType = CardType::fromInput($this->stringOrDefault($card->cardType, CardType::Recognition->value));
                    $studyStatus = CardStudyStatus::fromFilter(
                        $this->stringOrDefault($card->queueState, CardStudyStatus::New->value),
                    );

                    $insertRows[] = [
                        'id' => $id,
                        'deck_id' => $this->deckIds[$this->deckKey($card->userId, $deckName)],
                        'front_text' => $promptText,
                        'back_text' => $answerText,
                        'card_type' => $cardType->value,
                        'prompt_json' => $card->promptJson,
                        'answer_json' => $card->answerJson,
                        'search_text' => $this->stringOrDefault($card->searchText, trim($promptText.' '.$answerText)),
                        'study_status' => $studyStatus->value,
                        'due_at' => $card->dueAt,
                        'introduced_at' => $card->introducedAt,
                        'failed_at' => $card->failedAt,
                        'last_reviewed_at' => $card->lastReviewedAt,
                        'new_queue_position' => $card->newQueuePosition,
                        'scheduler_state' => $card->schedulerStateJson,
                        'import_job_id' => $this->mappedImportJobId($card->importJobId),
                        'source_kind' => $card->sourceKind,
                        'source_card_id' => $card->sourceCardId,
                        'source_note_id' => null,
                        'source_deck_id' => $card->sourceDeckId,
                        'source_notetype_name' => null,
                        'source_template_ord' => $card->sourceTemplateOrd,
                        'variant_group_id' => $card->variantGroupId,
                        'variant_sentence_id' => $card->variantSentenceId,
                        'variant_kind' => $card->variantKind,
                        'variant_stage' => $card->variantStage,
                        'variant_status' => $card->variantStatus,
                        'variant_unlocked_at' => $card->variantUnlockedAt,
                        'deleted_at' => null,
                        'created_at' => $card->createdAt,
                        'updated_at' => $card->updatedAt,
                    ];
                }

                $target->table('cards')->insert($insertRows);
                $count += count($insertRows);
            });

        $this->line("Imported {$count} cards.");
    }

    private function importCardMedia(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('study_cards')
            ->select('id', 'promptAudioMediaId', 'answerAudioMediaId', 'imageMediaId', 'createdAt', 'updatedAt')
            ->orderBy('id')
            ->chunk(500, function ($cards) use ($target, &$count): void {
                $rows = [];

                foreach ($cards as $card) {
                    foreach ([$card->promptAudioMediaId, $card->answerAudioMediaId, $card->imageMediaId] as $sourceMediaId) {
                        if ($sourceMediaId === null || $sourceMediaId === '') {
                            continue;
                        }

                        if (! is_string($sourceMediaId) || ! isset($this->mediaIds[$sourceMediaId])) {
                            throw new \RuntimeException("Missing imported media mapping for [{$sourceMediaId}].");
                        }

                        $rows[$this->cardIds[$card->id].':'.$this->mediaIds[$sourceMediaId]] = [
                            'card_id' => $this->cardIds[$card->id],
                            'media_asset_id' => $this->mediaIds[$sourceMediaId],
                            'created_at' => $card->createdAt,
                            'updated_at' => $card->updatedAt,
                        ];
                    }
                }

                if ($rows !== []) {
                    $target->table('card_media')->insert(array_values($rows));
                }

                $count += count($rows);
            });

        $this->line("Imported {$count} card media links.");
    }

    private function importReviewLogs(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $count = 0;

        $source->table('study_review_logs')
            ->orderBy('reviewedAt')
            ->orderBy('id')
            ->chunk(500, function ($reviews) use ($target, &$count): void {
                $insertRows = [];

                foreach ($reviews as $review) {
                    if (! isset($this->cardIds[$review->cardId])) {
                        throw new \RuntimeException("Missing imported card mapping for review [{$review->id}].");
                    }

                    $insertRows[] = [
                        'id' => (string) Str::ulid(),
                        'card_id' => $this->cardIds[$review->cardId],
                        'rating' => $this->rating((int) $review->rating),
                        'reviewed_at' => $review->reviewedAt,
                        'created_at' => $review->createdAt,
                        'updated_at' => $review->createdAt,
                        'client_event_id' => null,
                        'device_id' => null,
                        'client_created_at' => null,
                        'scheduler_state_before' => $review->stateBeforeJson,
                        'scheduler_state_after' => $review->stateAfterJson,
                        'duration_ms' => $review->durationMs,
                        'card_state_before' => $review->stateBeforeJson,
                        'import_job_id' => $this->mappedImportJobId($review->importJobId),
                        'source_kind' => $review->source,
                        'source_review_id' => $review->sourceReviewId,
                        'source_card_id' => null,
                        'source_ease' => $review->sourceEase,
                        'source_interval' => $review->sourceInterval,
                        'source_last_interval' => $review->sourceLastInterval,
                        'source_factor' => $review->sourceFactor,
                        'source_time_ms' => $review->sourceTimeMs,
                        'source_review_type' => $review->sourceReviewType,
                        'raw_payload_json' => $review->rawPayloadJson,
                    ];
                }

                if ($insertRows !== []) {
                    $target->table('card_review_events')->insert($insertRows);
                }

                $count += count($insertRows);
            });

        $this->line("Imported {$count} review events.");
    }

    private function mappedUserId(string $sourceUserId): int
    {
        return $this->userIds[$sourceUserId]
            ?? throw new \RuntimeException("Missing imported user mapping for [{$sourceUserId}].");
    }

    private function mappedImportJobId(?string $sourceImportJobId): ?string
    {
        if ($sourceImportJobId === null || $sourceImportJobId === '') {
            return null;
        }

        return $this->importJobIds[$sourceImportJobId]
            ?? throw new \RuntimeException("Missing imported study job mapping for [{$sourceImportJobId}].");
    }

    private function deckKey(string $sourceUserId, string $deckName): string
    {
        return $sourceUserId."\n".$deckName;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private function payloadText(?string $json): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return '';
        }

        $texts = [];
        array_walk_recursive($decoded, function (mixed $value, string|int $key) use (&$texts): void {
            if (($key === 'text' || $key === 'expression') && is_string($value) && trim($value) !== '') {
                $texts[] = trim($value);
            }
        });

        return Str::limit(implode(' ', array_unique($texts)), 1000, '');
    }

    private function rating(int $rating): string
    {
        return match ($rating) {
            1 => 'again',
            2 => 'hard',
            3 => 'good',
            4 => 'easy',
            default => throw new \RuntimeException("Unsupported Convo Lab review rating [{$rating}]."),
        };
    }
}
