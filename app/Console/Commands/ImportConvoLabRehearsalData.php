<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSource;
use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Support\Content\ConvoLabContentTables;
use App\Support\Identifiers\CanonicalUlid;
use App\Support\Rehearsal\ConvoLabCardMediaImporter;
use App\Support\Rehearsal\ConvoLabDailyAudioImporter;
use App\Support\Rehearsal\ConvoLabReviewImporter;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class ImportConvoLabRehearsalData extends Command
{
    use ConnectsToConvoLabSource;

    /**
     * Complete target reset boundary, including tables reached through user/deck/card foreign keys.
     * Keep this list synchronized when a migration adds a new foreign key into that ownership graph.
     *
     * @var list<string>
     */
    private const TARGET_TABLES = [
        'admin_sentence_script_tests',
        'admin_course_line_renderings',
        ...ConvoLabContentTables::RESET_IN_DELETE_ORDER,
        'card_media',
        'card_learning_concepts',
        'achievement_card_projections',
        'card_review_events',
        'sync_feed_entries',
        'daily_audio_practice_tracks',
        'daily_audio_practices',
        'study_card_drafts',
        'study_vocab_variant_sentences',
        'study_vocab_variant_groups',
        'media_assets',
        'cards',
        'card_introduction_cohorts',
        'decks',
        'courses',
        'study_import_jobs',
        'achievement_study_session_projections',
        'achievement_progress_projections',
        'achievement_awards',
        'study_milestones',
        'study_milestone_profiles',
        'study_settings',
        'study_activity_sessions',
        'user_known_kanji',
        'wanikani_subject_learning_concepts',
        'user_wanikani_assignments',
        'wanikani_subjects',
        'google_calendar_connect_intents',
        'google_calendar_event_mirrors',
        'google_calendar_connections',
        'wanikani_connections',
        'japanese_knowledge_profiles',
        'personal_access_tokens',
        'sessions',
        'password_reset_tokens',
        'convolab_oauth_identities',
        'convolab_email_verification_tokens',
        'admin_invite_codes',
        'admin_user_projections',
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
        {--skip-media : Omit media assets and card-media links when source byte sizes are unavailable}
        {--allow-production : Permit the importer to run when APP_ENV=production}
        {--production-truncate-confirmation= : Required production phrase: TRUNCATE <target database>}';

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
     * @var array<string, int>
     */
    private array $mediaUserIds = [];

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
            $this->assertProductionTruncateConfirmed($target, 'truncation');
            $source = $this->sourceConnection();

            if ($this->sameDatabase($source, $target)) {
                throw new \RuntimeException(
                    'Source and target databases resolve to the same database. Use a separate restored source copy.',
                );
            }

            $this->assertConvoLabSource($source);
            $this->assertProductionMediaStrategy($source);
            $this->assertTargetIsReady($target);

            $this->info('Importing Convo Lab rehearsal data');

            $target->transaction(function () use ($source, $target): void {
                if ($this->option('truncate')) {
                    $this->truncateTarget($target);
                }

                $this->importUsers($source, $target);
                $dailyAudioCounts = (new ConvoLabDailyAudioImporter(
                    fn (mixed $value, string $label): string => $this->sourceUuid($value, $label),
                    fn (string $sourceUserId): int => $this->mappedUserId($sourceUserId),
                ))->import($source, $target);
                $this->line(
                    "Imported {$dailyAudioCounts['practices']} daily audio practices and {$dailyAudioCounts['tracks']} tracks.",
                );
                $this->importDecks($source, $target);
                $this->importStudySettings($source, $target);
                $this->importStudyImportJobs($source, $target);
                if ($this->option('skip-media')) {
                    $this->line('Skipped media assets and card media links; Convo Lab does not store byte sizes.');
                } else {
                    $this->warn('Importing metadata-only media with size_bytes=0 for disposable rehearsal use.');
                    $this->importMedia($source, $target);
                }

                $this->importCards($source, $target);

                if (! $this->option('skip-media')) {
                    $cardMediaCount = (new ConvoLabCardMediaImporter(
                        $this->cardIds,
                        $this->mediaIds,
                        $this->mediaUserIds,
                        $this->userIds,
                    ))->import($source, $target);
                    $this->line("Imported {$cardMediaCount} card media links.");
                }

                $reviewCount = (new ConvoLabReviewImporter($this->cardIds, $this->importJobIds))
                    ->import($source, $target);
                $this->line("Imported {$reviewCount} review events.");
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Convo Lab rehearsal import completed.');

        return self::SUCCESS;
    }

    private function resetMappings(): void
    {
        $this->userIds = [];
        $this->deckIds = [];
        $this->importJobIds = [];
        $this->cardIds = [];
        $this->mediaIds = [];
        $this->mediaUserIds = [];
        $this->mediaPathIds = [];
        $this->mediaPathUserIds = [];
    }

    private function assertProductionMediaStrategy(ConnectionInterface $source): void
    {
        if (! app()->isProduction() || $this->option('skip-media') || ! $source->table('study_media')->exists()) {
            return;
        }

        throw new \RuntimeException(
            'Production import requires --skip-media because Convo Lab does not store media byte sizes.',
        );
    }

    private function assertConvoLabSource(ConnectionInterface $source): void
    {
        foreach ([
            'User',
            'daily_audio_practices',
            'daily_audio_practice_tracks',
            'study_cards',
            'study_review_logs',
            'study_media',
            'study_import_jobs',
            'study_settings',
        ] as $table) {
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
        $sourceUserIdsByEmail = [];

        $source->table('User')
            ->orderBy('createdAt')
            ->orderBy('id')
            ->chunk(200, function ($users) use ($target, &$count, &$sourceUserIdsByEmail): void {
                foreach ($users as $user) {
                    $convoLabUserId = $this->sourceUuid($user->id, 'user');
                    $normalizedEmail = strtolower(trim((string) $user->email));

                    if ($normalizedEmail === '') {
                        throw new \RuntimeException("Convo Lab user [{$user->id}] has no email.");
                    }

                    if (isset($sourceUserIdsByEmail[$normalizedEmail])) {
                        throw new \RuntimeException("Multiple Convo Lab users share email [{$user->email}].");
                    }

                    $sourceUserIdsByEmail[$normalizedEmail] = $user->id;
                    // Convo Lab uses Node bcrypt; importedPassword normalizes its prefix for PHP.
                    $password = $this->importedPassword($user);

                    $targetId = $target->table('users')->insertGetId([
                        'name' => $user->displayName ?: $user->name ?: $user->email,
                        'email' => $user->email,
                        'convolab_id' => $convoLabUserId,
                        'email_verified_at' => $user->emailVerifiedAt,
                        'password' => $password,
                        'remember_token' => null,
                        'created_at' => $user->createdAt,
                        'updated_at' => $user->updatedAt,
                    ]);

                    $this->userIds[$user->id] = (int) $targetId;
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

            $deckId = $this->newCanonicalUlid();

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
                    $id = $this->newCanonicalUlid();
                    $convoLabId = $this->sourceUuid($job->id, 'import job');

                    $this->importJobIds[$job->id] = $id;
                    $status = StudyImportStatus::tryFrom($job->status)
                        ?? throw new \RuntimeException("Unsupported Convo Lab import status [{$job->status}].");

                    $target->table('study_import_jobs')->insert([
                        'id' => $id,
                        'user_id' => $this->mappedUserId($job->userId),
                        'convolab_id' => $convoLabId,
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
                    $this->mediaUserIds[$media->id] = $userId;

                    if (isset($this->mediaPathIds[$pathKey])) {
                        if ($this->mediaPathUserIds[$pathKey] !== $userId) {
                            throw new \RuntimeException("Media path [{$path}] is shared by multiple Convo Lab users.");
                        }

                        $this->mediaIds[$media->id] = $this->mediaPathIds[$pathKey];
                        $deduped++;

                        continue;
                    }

                    $id = $this->newCanonicalUlid();
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

        $source->table('study_cards as cards')
            ->leftJoin('study_notes as notes', 'notes.id', '=', 'cards.noteId')
            ->select('cards.*')
            ->addSelect([
                'notes.id as importedNoteId',
                'notes.sourceNoteId as noteSourceId',
                'notes.sourceKind as noteSourceKind',
                'notes.sourceGuid as noteSourceGuid',
                'notes.sourceNotetypeId as noteSourceNotetypeId',
                'notes.sourceNotetypeName as noteTypeName',
                'notes.rawFieldsJson as noteRawFieldsJson',
                'notes.canonicalJson as noteCanonicalJson',
                'notes.userId as noteUserId',
                'notes.createdAt as noteCreatedAt',
                'notes.updatedAt as noteUpdatedAt',
            ])
            ->orderBy('cards.createdAt')
            ->orderBy('cards.id')
            ->chunk(500, function ($cards) use ($target, &$count): void {
                $insertRows = [];

                foreach ($cards as $card) {
                    $insertRows[] = $this->cardInsertRow($card);
                }

                $target->table('cards')->insert($insertRows);
                $count += count($insertRows);
            });

        $this->line("Imported {$count} cards.");
    }

    /**
     * @return array<string, mixed>
     */
    private function cardInsertRow(object $card): array
    {
        $this->assertCardNoteIsImportable($card);

        $id = $this->newCanonicalUlid();
        $this->cardIds[$card->id] = $id;
        $deckName = $this->stringOrDefault($card->sourceDeckName, 'Convo Lab Study Cards');
        $promptText = $this->payloadText($card->promptJson, [
            'cueText',
            'clozeText',
            'clozeDisplayText',
            'cueMeaning',
            'text',
            'expression',
            'cueHtml',
        ]);
        $answerText = $this->payloadText($card->answerJson, ['meaning', 'text', 'expression', 'notes']);
        $cardType = CardType::fromInput($this->stringOrDefault($card->cardType, CardType::Recognition->value));
        $studyStatus = CardStudyStatus::fromFilter(
            $this->stringOrDefault($card->queueState, CardStudyStatus::New->value),
        );

        return [
            ...$this->cardIdentityColumns($card, $id, $deckName),
            ...$this->cardContentColumns($card, $promptText, $answerText, $cardType),
            ...$this->cardStudyColumns($card, $studyStatus),
            ...$this->cardSourceColumns($card),
            ...$this->cardVariantColumns($card),
        ];
    }

    private function assertCardNoteIsImportable(object $card): void
    {
        if (! is_string($card->importedNoteId) || $card->importedNoteId === '') {
            throw new \RuntimeException("Missing Convo Lab note [{$card->noteId}] for card [{$card->id}].");
        }

        if ($card->noteUserId !== $card->userId) {
            throw new \RuntimeException("Convo Lab card [{$card->id}] references a note owned by another user.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cardIdentityColumns(object $card, string $id, string $deckName): array
    {
        $convoLabId = $this->sourceUuid($card->id, 'card');
        $convoLabNoteId = $this->sourceUuid($card->noteId, 'note');
        $deckKey = $this->deckKey($card->userId, $deckName);
        $deckId = $this->deckIds[$deckKey]
            ?? throw new \RuntimeException("Missing imported deck mapping for [{$deckKey}].");

        return [
            'id' => $id,
            'convolab_id' => $convoLabId,
            'convolab_note_id' => $convoLabNoteId,
            'convolab_note_created_at' => $card->noteCreatedAt,
            'convolab_note_updated_at' => $card->noteUpdatedAt,
            'convolab_note_source_kind' => $card->noteSourceKind,
            'convolab_note_source_guid' => $card->noteSourceGuid,
            'convolab_note_source_notetype_id' => $card->noteSourceNotetypeId,
            'convolab_note_raw_fields_json' => $card->noteRawFieldsJson,
            'convolab_note_canonical_json' => $card->noteCanonicalJson,
            'deck_id' => $deckId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardContentColumns(
        object $card,
        string $promptText,
        string $answerText,
        CardType $cardType,
    ): array {
        return [
            'front_text' => $promptText,
            'back_text' => $answerText,
            'card_type' => $cardType->value,
            'prompt_json' => $card->promptJson,
            'answer_json' => $card->answerJson,
            'search_text' => $this->stringOrDefault($card->searchText, trim($promptText.' '.$answerText)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardStudyColumns(object $card, CardStudyStatus $studyStatus): array
    {
        return [
            'study_status' => $studyStatus->value,
            'due_at' => $card->dueAt,
            'introduced_at' => $card->introducedAt,
            'failed_at' => $card->failedAt,
            'last_reviewed_at' => $card->lastReviewedAt,
            'new_queue_position' => $card->newQueuePosition,
            'scheduler_state' => $card->schedulerStateJson,
            'import_job_id' => $this->mappedImportJobId($card->importJobId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardSourceColumns(object $card): array
    {
        return [
            'source_kind' => $card->sourceKind,
            'source_card_id' => $card->sourceCardId,
            'source_note_id' => $card->noteSourceId,
            'source_deck_id' => $card->sourceDeckId,
            'source_deck_name' => $card->sourceDeckName,
            'source_notetype_name' => $card->noteTypeName,
            'source_template_ord' => $card->sourceTemplateOrd,
            'source_template_name' => $card->sourceTemplateName,
            'source_queue' => $card->sourceQueue,
            'source_card_type' => $card->sourceCardType,
            'source_due' => $card->sourceDue,
            'source_interval' => $card->sourceInterval,
            'source_factor' => $card->sourceFactor,
            'source_reps' => $card->sourceReps,
            'source_lapses' => $card->sourceLapses,
            'source_left' => $card->sourceLeft,
            'source_original_due' => $card->sourceOriginalDue,
            'source_original_deck_id' => $card->sourceOriginalDeckId,
            'source_fsrs_json' => $card->sourceFsrsJson,
            'answer_audio_source' => $card->answerAudioSource,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cardVariantColumns(object $card): array
    {
        return [
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

    private function newCanonicalUlid(): string
    {
        return CanonicalUlid::normalize((string) Str::ulid());
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private function sourceUuid(mixed $value, string $label): string
    {
        $uuid = strtolower(trim((string) $value));

        if (! Str::isUuid($uuid)) {
            throw new \RuntimeException("Convo Lab {$label} [{$value}] does not have a valid UUID.");
        }

        return $uuid;
    }

    private function importedPassword(object $user): string
    {
        if (! is_string($user->password) || $user->password === '') {
            return Hash::make(Str::random(32));
        }

        $password = str_starts_with($user->password, '$2b$')
            ? '$2y$'.substr($user->password, 4)
            : $user->password;

        if ((password_get_info($password)['algoName'] ?? 'unknown') === 'unknown') {
            throw new \RuntimeException("Convo Lab user [{$user->id}] has an unsupported password hash.");
        }

        return $password;
    }

    /**
     * @param  list<string>  $keys
     */
    private function payloadText(?string $json, array $keys): string
    {
        if ($json === null || $json === '') {
            return '';
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return '';
        }

        foreach ($keys as $key) {
            $value = $decoded[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $text = str_ends_with($key, 'Html') ? strip_tags($value) : $value;

                return Str::limit(trim($text), 1000, '');
            }
        }

        return '';
    }
}
