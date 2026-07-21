<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSource;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Support\Content\ConvoLabContentTables;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportConvoLabEpisodes extends Command
{
    use ConnectsToConvoLabSource;

    private const SOURCE_TABLES = [
        'User',
        'Episode',
        'Dialogue',
        'Speaker',
        'Sentence',
        'Image',
        'audio_scripts',
        'study_media',
        'audio_script_segments',
        'audio_script_renders',
        'Course',
        'CourseCoreItem',
        'CourseEpisode',
    ];

    protected $signature = 'content:import-convolab-episodes
        {--source-connection=convolab_content : Temporary source connection name}
        {--source-database= : Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--truncate : Replace all imported Episode and Course content}
        {--allow-production : Permit the importer to run when APP_ENV=production}
        {--production-truncate-confirmation= : Required production phrase: TRUNCATE <target database>}';

    protected $description = 'Import Convo Lab Episode and Course read data into Learning OS.';

    /** @var array<string, int> */
    private array $userIds = [];

    /** @var array<string, true> */
    private array $episodeIds = [];

    /** @var array<string, true> */
    private array $courseIds = [];

    /** @var array<string, true> */
    private array $dialogueIds = [];

    /** @var array<string, true> */
    private array $speakerIds = [];

    /** @var array<string, true> */
    private array $scriptIds = [];

    /** @var array<string, true> */
    private array $mediaIds = [];

    /** @var array<string, true> */
    private array $referencedMediaIds = [];

    /** @var array<string, true> */
    private array $preservedEpisodeIds = [];

    /** @var array<string, true> */
    private array $preservedCourseIds = [];

    /** @var array<string, true> */
    private array $tombstonedCourseIds = [];

    /** @var array<string, true> */
    private array $preservedCourseEpisodeIds = [];

    /** @var array<string, int> */
    private array $preservedMediaUserIds = [];

    public function handle(): int
    {
        $this->resetMappings();

        if (app()->isProduction() && ! $this->option('allow-production')) {
            $this->error('This command must not run in production without --allow-production.');

            return self::FAILURE;
        }

        try {
            $target = DB::connection();
            $source = $this->sourceConnection();
            $this->assertDatabasesDiffer($source, $target);
            $this->assertSourceReady($source);
            $this->assertTargetSchemaReady($target);
            $this->assertProductionTruncateConfirmed($target, 'replacement');

            $target->transaction(function () use ($source, $target): void {
                ContentSourceLock::acquireConvoLab($target);
                $this->assertTargetImportState($target);
                $this->mapPreservedRecords($target);

                if ($this->option('truncate')) {
                    $this->replaceImportedTarget($target);
                }

                $this->mapUsers($source, $target);
                $this->importEpisodes($source, $target);
                $this->importDialogues($source, $target);
                $this->importSpeakers($source, $target);
                $this->importSentences($source, $target);
                $this->importImages($source, $target);
                $this->importAudioScripts($source, $target);
                $this->mapReferencedAudioScriptMedia($source);
                $this->importAudioScriptMedia($source, $target);
                $this->importAudioScriptSegments($source, $target);
                $this->importAudioScriptRenders($source, $target);
                $this->importCourses($source, $target);
                $this->importCourseCoreItems($source, $target);
                $this->importCourseEpisodes($source, $target);
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Convo Lab Episode and Course import completed.');

        return self::SUCCESS;
    }

    private function assertDatabasesDiffer(ConnectionInterface $source, ConnectionInterface $target): void
    {
        if ($this->sameDatabase($source, $target)) {
            throw new RuntimeException('Source and target databases must differ.');
        }
    }

    private function assertSourceReady(ConnectionInterface $source): void
    {
        foreach (self::SOURCE_TABLES as $table) {
            if (! $source->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Source database is missing Convo Lab table [{$table}].");
            }
        }
    }

    private function assertTargetSchemaReady(ConnectionInterface $target): void
    {
        foreach ([
            ...ConvoLabContentTables::CONTENT_IN_DELETE_ORDER,
            'content_course_tombstones',
            'content_episode_tombstones',
            'content_source_locks',
        ] as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Learning OS is missing target table [{$table}].");
            }
        }

        foreach (ConvoLabContentTables::IMPORT_OWNERSHIP_TABLES as $table) {
            if (! $target->getSchemaBuilder()->hasColumn($table, 'source_system')) {
                throw new RuntimeException("Learning OS target table [{$table}] has no source ownership column.");
            }
        }
    }

    private function assertTargetImportState(ConnectionInterface $target): void
    {
        foreach (ConvoLabContentTables::IMPORT_OWNERSHIP_TABLES as $table) {
            if (
                ! $this->option('truncate')
                && $target->table($table)
                    ->where('source_system', ContentSourceSystem::CONVOLAB)
                    ->exists()
            ) {
                throw new RuntimeException("Target already contains imported content in [{$table}]; rerun with --truncate.");
            }
        }
    }

    private function replaceImportedTarget(ConnectionInterface $target): void
    {
        foreach (ConvoLabContentTables::IMPORTED_ROOTS_IN_DELETE_ORDER as $table) {
            $count = $target->table($table)
                ->where('source_system', ContentSourceSystem::CONVOLAB)
                ->delete();
            $this->line("Removed {$count} previously imported rows from {$table}.");
        }
    }

    private function mapPreservedRecords(ConnectionInterface $target): void
    {
        foreach ($target->table('content_episodes')
            ->where('source_system', ContentSourceSystem::LEARNING_OS)
            ->pluck('id') as $episodeId) {
            $this->preservedEpisodeIds[(string) $episodeId] = true;
        }

        foreach ($target->table('content_episode_tombstones')->pluck('episode_id') as $episodeId) {
            $this->preservedEpisodeIds[(string) $episodeId] = true;
        }

        foreach ($target->table('content_courses')
            ->where('source_system', ContentSourceSystem::LEARNING_OS)
            ->pluck('id') as $courseId) {
            $this->preservedCourseIds[(string) $courseId] = true;
        }

        foreach ($target->table('content_course_tombstones')->pluck('course_id') as $courseId) {
            $courseId = (string) $courseId;
            $this->preservedCourseIds[$courseId] = true;
            $this->tombstonedCourseIds[$courseId] = true;
        }

        foreach ($target->table('content_episode_courses')
            ->where('source_system', ContentSourceSystem::LEARNING_OS)
            ->pluck('id') as $courseEpisodeId) {
            $this->preservedCourseEpisodeIds[(string) $courseEpisodeId] = true;
        }

        foreach ($target->table('content_audio_script_media')
            ->where('source_system', ContentSourceSystem::LEARNING_OS)
            ->get(['id', 'user_id']) as $media) {
            $this->preservedMediaUserIds[(string) $media->id] = (int) $media->user_id;
        }
    }

    private function mapUsers(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $targetUsers = $target->table('users')->get(['id', 'email'])
            ->mapWithKeys(fn (object $user): array => [strtolower(trim($user->email)) => (int) $user->id]);
        $sourceEmails = [];

        $source->table('User')->orderBy('id')->chunk(200, function ($users) use ($targetUsers, &$sourceEmails): void {
            foreach ($users as $user) {
                $email = strtolower(trim((string) $user->email));
                if ($email !== '' && isset($sourceEmails[$email])) {
                    throw new RuntimeException("Source contains duplicate normalized user email [{$email}].");
                }
                $sourceEmails[$email] = true;

                if ($email !== '' && $targetUsers->has($email)) {
                    $this->userIds[$this->uuid($user->id, 'user')] = (int) $targetUsers->get($email);
                }
            }
        });
    }

    private function resetMappings(): void
    {
        $this->userIds = [];
        $this->episodeIds = [];
        $this->courseIds = [];
        $this->dialogueIds = [];
        $this->speakerIds = [];
        $this->scriptIds = [];
        $this->mediaIds = [];
        $this->referencedMediaIds = [];
        $this->preservedEpisodeIds = [];
        $this->preservedCourseIds = [];
        $this->tombstonedCourseIds = [];
        $this->preservedCourseEpisodeIds = [];
        $this->preservedMediaUserIds = [];
    }

    private function importEpisodes(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Episode', 'content_episodes', function (object $row): ?array {
            $id = $this->uuid($row->id, 'episode');
            if (isset($this->preservedEpisodeIds[$id])) {
                return null;
            }

            $sourceUserId = $this->uuid($row->userId, 'episode user');
            $userId = $this->userIds[$sourceUserId]
                ?? throw new RuntimeException("Episode [{$id}] belongs to an unmapped user.");
            $this->episodeIds[$id] = true;

            return [
                'id' => $id,
                'user_id' => $userId,
                'convolab_user_id' => $sourceUserId,
                'source_system' => ContentSourceSystem::CONVOLAB,
                'title' => $row->title,
                'source_text' => $row->sourceText,
                'target_language' => $row->targetLanguage,
                'native_language' => $row->nativeLanguage,
                'content_type' => $this->contentType($row->contentType, $id),
                'jlpt_level' => $row->jlptLevel,
                'auto_generate_audio' => $row->autoGenerateAudio,
                'status' => $row->status,
                'is_sample_content' => $row->isSampleContent,
                'audio_url' => $row->audioUrl,
                'audio_speed' => $row->audioSpeed,
                'audio_url_0_7' => $row->audioUrl_0_7,
                'audio_url_0_85' => $row->audioUrl_0_85,
                'audio_url_1_0' => $row->audioUrl_1_0,
                'created_at' => $row->createdAt,
                'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importDialogues(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Dialogue', 'content_dialogues', function (object $row): ?array {
            $episodeId = $this->uuid($row->episodeId, 'dialogue episode');
            if (! isset($this->episodeIds[$episodeId])) {
                return null;
            }
            $id = $this->uuid($row->id, 'dialogue');
            $this->dialogueIds[$id] = true;

            return ['id' => $id, 'episode_id' => $episodeId, 'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt];
        });
    }

    private function importSpeakers(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Speaker', 'content_speakers', function (object $row): ?array {
            $dialogueId = $this->uuid($row->dialogueId, 'speaker dialogue');
            if (! isset($this->dialogueIds[$dialogueId])) {
                return null;
            }
            $id = $this->uuid($row->id, 'speaker');
            $this->speakerIds[$id] = true;

            return [
                'id' => $id, 'dialogue_id' => $dialogueId, 'name' => $row->name,
                'voice_id' => $row->voiceId, 'voice_provider' => $row->voiceProvider,
                'proficiency' => $row->proficiency, 'tone' => $row->tone, 'gender' => $row->gender,
                'color' => $row->color, 'avatar_url' => $row->avatarUrl,
            ];
        });
    }

    private function importSentences(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Sentence', 'content_sentences', function (object $row): ?array {
            $dialogueId = $this->uuid($row->dialogueId, 'sentence dialogue');
            $speakerId = $this->uuid($row->speakerId, 'sentence speaker');
            if (! isset($this->dialogueIds[$dialogueId], $this->speakerIds[$speakerId])) {
                return null;
            }

            return [
                'id' => $this->uuid($row->id, 'sentence'), 'dialogue_id' => $dialogueId,
                'speaker_id' => $speakerId, 'sort_order' => $row->order, 'text' => $row->text,
                'translation' => $row->translation,
                'metadata' => $this->requiredSourceValue($row->metadata, "Sentence [{$row->id}] metadata"),
                'audio_url' => $row->audioUrl, 'start_time' => $row->startTime, 'end_time' => $row->endTime,
                'start_time_0_7' => $row->startTime_0_7, 'end_time_0_7' => $row->endTime_0_7,
                'start_time_0_85' => $row->startTime_0_85, 'end_time_0_85' => $row->endTime_0_85,
                'start_time_1_0' => $row->startTime_1_0, 'end_time_1_0' => $row->endTime_1_0,
                'variations' => $row->variations, 'selected' => $row->selected,
                'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importImages(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Image', 'content_images', function (object $row): ?array {
            $episodeId = $this->uuid($row->episodeId, 'image episode');
            if (! isset($this->episodeIds[$episodeId])) {
                return null;
            }

            return [
                'id' => $this->uuid($row->id, 'image'), 'episode_id' => $episodeId,
                'url' => $this->requiredSourceValue($row->url, "Image [{$row->id}] URL"),
                'prompt' => $this->requiredSourceValue($row->prompt, "Image [{$row->id}] prompt"),
                'sort_order' => $row->order,
                'sentence_start_id' => $row->sentenceStartId, 'sentence_end_id' => $row->sentenceEndId,
                'created_at' => $row->createdAt,
            ];
        });
    }

    private function importAudioScripts(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'audio_scripts', 'content_audio_scripts', function (object $row): ?array {
            $episodeId = $this->uuid($row->episodeId, 'audio script episode');
            if (! isset($this->episodeIds[$episodeId])) {
                return null;
            }
            $id = $this->uuid($row->id, 'audio script');
            $this->scriptIds[$id] = true;

            return [
                'id' => $id, 'episode_id' => $episodeId, 'status' => $row->status,
                'image_status' => $row->imageStatus, 'image_error_message' => $row->imageErrorMessage,
                'voice_id' => $row->voiceId, 'voice_provider' => $row->voiceProvider,
                'generation_metadata' => $row->generationMetadataJson, 'error_message' => $row->errorMessage,
                'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importAudioScriptMedia(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'study_media', 'content_audio_script_media', function (object $row): ?array {
            $id = $this->uuid($row->id, 'audio script media');
            if (! isset($this->referencedMediaIds[$id])) {
                return null;
            }

            $sourceUserId = $this->uuid($row->userId, 'audio script media user');
            $targetUserId = $this->userIds[$sourceUserId] ?? null;
            if ($targetUserId === null) {
                throw new RuntimeException("Referenced audio script media [{$id}] belongs to an unmapped user.");
            }

            if (isset($this->preservedMediaUserIds[$id])) {
                if ($this->preservedMediaUserIds[$id] !== $targetUserId) {
                    throw new RuntimeException("Preserved audio script media [{$id}] belongs to a different user.");
                }

                $this->mediaIds[$id] = true;

                return null;
            }

            $this->mediaIds[$id] = true;

            return [
                'id' => $id, 'user_id' => $targetUserId, 'source_kind' => $row->sourceKind,
                'source_system' => ContentSourceSystem::CONVOLAB,
                'source_filename' => $row->sourceFilename, 'normalized_filename' => $row->normalizedFilename,
                'media_kind' => $row->mediaKind, 'content_type' => $row->contentType,
                'storage_path' => $row->storagePath, 'public_url' => $row->publicUrl,
                'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function mapReferencedAudioScriptMedia(ConnectionInterface $source): void
    {
        $source->table('audio_script_segments')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                $scriptId = $this->uuid($row->scriptId, 'audio segment script');
                if (isset($this->scriptIds[$scriptId]) && $row->imageMediaId !== null) {
                    $this->referencedMediaIds[$this->uuid($row->imageMediaId, 'audio segment media')] = true;
                }
            }
        });
    }

    private function importAudioScriptSegments(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'audio_script_segments', 'content_audio_script_segments', function (object $row): ?array {
            $scriptId = $this->uuid($row->scriptId, 'audio segment script');
            if (! isset($this->scriptIds[$scriptId])) {
                return null;
            }
            $mediaId = $row->imageMediaId === null ? null : $this->uuid($row->imageMediaId, 'audio segment media');

            return [
                'id' => $this->uuid($row->id, 'audio segment'), 'script_id' => $scriptId,
                'sort_order' => $row->order, 'text' => $row->text, 'reading' => $row->reading,
                'translation' => $row->translation, 'image_prompt' => $row->imagePrompt,
                'image_status' => $row->imageStatus, 'image_error_message' => $row->imageErrorMessage,
                'image_media_id' => $mediaId !== null && isset($this->mediaIds[$mediaId]) ? $mediaId : null,
                'image_generated_at' => $row->imageGeneratedAt, 'metadata' => $row->metadata,
                'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importAudioScriptRenders(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'audio_script_renders', 'content_audio_script_renders', function (object $row): ?array {
            $scriptId = $this->uuid($row->scriptId, 'audio render script');
            if (! isset($this->scriptIds[$scriptId])) {
                return null;
            }

            return [
                'id' => $this->uuid($row->id, 'audio render'), 'script_id' => $scriptId,
                'speed' => $row->speed, 'numeric_speed' => $row->numericSpeed, 'status' => $row->status,
                'audio_url' => $row->audioUrl, 'timing_data' => $row->timingData,
                'approx_duration_seconds' => $row->approxDurationSeconds, 'error_message' => $row->errorMessage,
                'created_at' => $row->createdAt, 'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importCourseEpisodes(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'CourseEpisode', 'content_episode_courses', function (object $row): ?array {
            $id = $this->uuid($row->id, 'course episode link');
            if (isset($this->preservedCourseEpisodeIds[$id])) {
                return null;
            }
            $episodeId = $this->uuid($row->episodeId, 'course episode');
            $courseId = $this->uuid($row->courseId, 'course episode course');
            if ((! isset($this->episodeIds[$episodeId]) && ! isset($this->preservedEpisodeIds[$episodeId]))
                || ! isset($this->courseIds[$courseId])) {
                return null;
            }

            return [
                'id' => $id, 'episode_id' => $episodeId,
                'convolab_course_id' => $courseId, 'sort_order' => $row->order,
                'source_system' => ContentSourceSystem::CONVOLAB,
            ];
        });
    }

    private function importCourses(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Course', 'content_courses', function (object $row): ?array {
            $id = $this->uuid($row->id, 'course');
            if (isset($this->preservedCourseIds[$id])) {
                if (! isset($this->tombstonedCourseIds[$id])) {
                    $this->courseIds[$id] = true;
                }

                return null;
            }

            $sourceUserId = $this->uuid($row->userId, 'course user');
            $userId = $this->userIds[$sourceUserId]
                ?? throw new RuntimeException("Course [{$id}] belongs to an unmapped user.");
            $this->courseIds[$id] = true;

            return [
                'id' => $id,
                'user_id' => $userId,
                'convolab_user_id' => $sourceUserId,
                'source_system' => ContentSourceSystem::CONVOLAB,
                'title' => $row->title,
                'description' => $row->description,
                'status' => $row->status,
                'is_sample_content' => $row->isSampleContent,
                'is_test_course' => $row->isTestCourse,
                'native_language' => $row->nativeLanguage,
                'target_language' => $row->targetLanguage,
                'max_lesson_duration_minutes' => $row->maxLessonDurationMinutes,
                'l1_voice_id' => $row->l1VoiceId,
                'l1_voice_provider' => $row->l1VoiceProvider,
                'jlpt_level' => $row->jlptLevel,
                'speaker1_gender' => $row->speaker1Gender,
                'speaker2_gender' => $row->speaker2Gender,
                'speaker1_voice_id' => $row->speaker1VoiceId,
                'speaker1_voice_provider' => $row->speaker1VoiceProvider,
                'speaker2_voice_id' => $row->speaker2VoiceId,
                'speaker2_voice_provider' => $row->speaker2VoiceProvider,
                'script_json' => $row->scriptJson,
                'script_units_json' => $row->scriptUnitsJson,
                'approx_duration_seconds' => $row->approxDurationSeconds,
                'audio_url' => $row->audioUrl,
                'timing_data' => $row->timingData,
                'created_at' => $row->createdAt,
                'updated_at' => $row->updatedAt,
            ];
        });
    }

    private function importCourseCoreItems(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'CourseCoreItem', 'content_course_core_items', function (object $row): ?array {
            $courseId = $this->uuid($row->courseId, 'course core item course');
            if (! isset($this->courseIds[$courseId]) || isset($this->preservedCourseIds[$courseId])) {
                return null;
            }

            return [
                'id' => $this->uuid($row->id, 'course core item'),
                'course_id' => $courseId,
                'text_l2' => $row->textL2,
                'reading_l2' => $row->readingL2,
                'translation_l1' => $row->translationL1,
                'complexity_score' => $row->complexityScore,
                'source_episode_id' => $this->nullableUuid($row->sourceEpisodeId, 'course core item source episode'),
                'source_sentence_id' => $this->nullableUuid($row->sourceSentenceId, 'course core item source sentence'),
                'source_unit_index' => $row->sourceUnitIndex,
                'components' => $row->components,
            ];
        });
    }

    private function copy(
        ConnectionInterface $source,
        ConnectionInterface $target,
        string $sourceTable,
        string $targetTable,
        callable $map,
    ): void {
        $count = 0;
        $source->table($sourceTable)->orderBy('id')->chunk(200, function ($sourceRows) use ($target, $targetTable, $map, &$count): void {
            $rows = [];
            foreach ($sourceRows as $sourceRow) {
                $row = $map($sourceRow);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            if ($rows !== []) {
                $target->table($targetTable)->insert($rows);
                $count += count($rows);
            }
        });
        $this->line("Imported {$count} rows into {$targetTable}.");
    }

    private function uuid(mixed $value, string $label): string
    {
        $uuid = strtolower(trim((string) $value));
        if (! Str::isUuid($uuid)) {
            throw new RuntimeException("Convo Lab {$label} ID [{$value}] is not a UUID.");
        }

        return $uuid;
    }

    private function nullableUuid(mixed $value, string $label): ?string
    {
        return $value === null ? null : $this->uuid($value, $label);
    }

    private function contentType(mixed $value, string $episodeId): string
    {
        $contentType = strtolower(trim((string) $value));
        if (! in_array($contentType, ['dialogue', 'script'], true)) {
            throw new RuntimeException("Episode [{$episodeId}] has unsupported content type [{$value}].");
        }

        return $contentType;
    }

    private function requiredSourceValue(mixed $value, string $label): mixed
    {
        if ($value === null) {
            throw new RuntimeException("{$label} must not be null.");
        }

        return $value;
    }
}
