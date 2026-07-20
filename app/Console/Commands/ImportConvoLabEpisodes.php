<?php

namespace App\Console\Commands;

use App\Console\Concerns\ConnectsToConvoLabSource;
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
        'CourseEpisode',
    ];

    protected $signature = 'content:import-convolab-episodes
        {--source-connection=convolab_content : Temporary source connection name}
        {--source-database= : Convo Lab source database name}
        {--source-host= : Source database host; defaults to DB_HOST}
        {--source-port= : Source database port; defaults to DB_PORT}
        {--source-username= : Source database username; defaults to DB_USERNAME}
        {--source-password= : Source database password; defaults to DB_PASSWORD}
        {--truncate : Replace all imported Episode content}
        {--allow-production : Permit the importer to run when APP_ENV=production}
        {--production-truncate-confirmation= : Required production phrase: TRUNCATE <target database>}';

    protected $description = 'Import Convo Lab Episode read data into Learning OS.';

    /** @var array<string, int> */
    private array $userIds = [];

    /** @var array<string, true> */
    private array $episodeIds = [];

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
            $this->assertTargetReady($target);
            $this->assertProductionTruncateConfirmed($target);

            $target->transaction(function () use ($source, $target): void {
                if ($this->option('truncate')) {
                    $this->truncateTarget($target);
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
                $this->importCourseEpisodes($source, $target);
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Convo Lab Episode import completed.');

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

    private function assertTargetReady(ConnectionInterface $target): void
    {
        foreach (ConvoLabContentTables::TARGET_IN_DELETE_ORDER as $table) {
            if (! $target->getSchemaBuilder()->hasTable($table)) {
                throw new RuntimeException("Learning OS is missing target table [{$table}].");
            }

            if (! $this->option('truncate') && $target->table($table)->exists()) {
                throw new RuntimeException("Target table [{$table}] is not empty; rerun with --truncate.");
            }
        }
    }

    private function assertProductionTruncateConfirmed(ConnectionInterface $target): void
    {
        if (! app()->isProduction() || ! $this->option('truncate')) {
            return;
        }

        $expected = 'TRUNCATE '.$target->getDatabaseName();
        if ($this->option('production-truncate-confirmation') !== $expected) {
            throw new RuntimeException(
                "Production replacement requires --production-truncate-confirmation=\"{$expected}\".",
            );
        }
    }

    private function truncateTarget(ConnectionInterface $target): void
    {
        foreach (ConvoLabContentTables::TARGET_IN_DELETE_ORDER as $table) {
            $target->table($table)->delete();
        }
    }

    private function mapUsers(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $targetUsers = $target->table('users')->get(['id', 'email'])
            ->mapWithKeys(fn (object $user): array => [strtolower(trim($user->email)) => (int) $user->id]);
        $sourceEmails = [];

        foreach ($source->table('User')->get(['id', 'email']) as $user) {
            $email = strtolower(trim((string) $user->email));
            if ($email !== '' && isset($sourceEmails[$email])) {
                throw new RuntimeException("Source contains duplicate normalized user email [{$email}].");
            }
            $sourceEmails[$email] = true;

            if ($email !== '' && $targetUsers->has($email)) {
                $this->userIds[$this->uuid($user->id, 'user')] = (int) $targetUsers->get($email);
            }
        }
    }

    private function resetMappings(): void
    {
        $this->userIds = [];
        $this->episodeIds = [];
        $this->dialogueIds = [];
        $this->speakerIds = [];
        $this->scriptIds = [];
        $this->mediaIds = [];
        $this->referencedMediaIds = [];
    }

    private function importEpisodes(ConnectionInterface $source, ConnectionInterface $target): void
    {
        $this->copy($source, $target, 'Episode', 'content_episodes', function (object $row): array {
            $id = $this->uuid($row->id, 'episode');
            $sourceUserId = $this->uuid($row->userId, 'episode user');
            $userId = $this->userIds[$sourceUserId]
                ?? throw new RuntimeException("Episode [{$id}] belongs to an unmapped user.");
            $this->episodeIds[$id] = true;

            return [
                'id' => $id,
                'user_id' => $userId,
                'convolab_user_id' => $sourceUserId,
                'title' => $row->title,
                'source_text' => $row->sourceText,
                'target_language' => $row->targetLanguage,
                'native_language' => $row->nativeLanguage,
                'content_type' => $row->contentType,
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
                'translation' => $row->translation, 'metadata' => $row->metadata,
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
                'url' => $row->url, 'prompt' => $row->prompt, 'sort_order' => $row->order,
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
            if (! isset($this->userIds[$sourceUserId])) {
                throw new RuntimeException("Referenced audio script media [{$id}] belongs to an unmapped user.");
            }
            $this->mediaIds[$id] = true;

            return [
                'id' => $id, 'user_id' => $this->userIds[$sourceUserId], 'source_kind' => $row->sourceKind,
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
            $episodeId = $this->uuid($row->episodeId, 'course episode');
            if (! isset($this->episodeIds[$episodeId])) {
                return null;
            }

            return [
                'id' => $this->uuid($row->id, 'course episode link'), 'episode_id' => $episodeId,
                'convolab_course_id' => $this->uuid($row->courseId, 'course episode course'),
                'sort_order' => $row->order,
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
}
