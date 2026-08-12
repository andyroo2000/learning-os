<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Exceptions\ContentCreationConflictException;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeTombstone;
use App\Domain\Content\Results\CreateContentEpisodeResult;
use App\Domain\Content\Support\ContentCreationFingerprint;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateContentEpisodeAction
{
    /**
     * Legacy creates without a client ID intentionally remain non-idempotent.
     */
    public function handle(CreateContentEpisodeData $data): CreateContentEpisodeResult
    {
        $fingerprint = $data->id === null ? null : ContentCreationFingerprint::episode($data);

        if ($data->id !== null) {
            $this->assertNotTombstoned($data);
            $existing = ContentEpisode::query()->find($data->id);
            if ($existing instanceof ContentEpisode) {
                return CreateContentEpisodeResult::existing($this->matchingExisting($existing, $data, $fingerprint));
            }
        }

        try {
            $result = DB::transaction(function () use ($data, $fingerprint): CreateContentEpisodeResult {
                ContentSourceLock::acquireConvoLab(DB::connection());

                if ($data->id !== null) {
                    $this->assertNotTombstoned($data, true);
                    $existing = ContentEpisode::query()->whereKey($data->id)->lockForUpdate()->first();
                    if ($existing instanceof ContentEpisode) {
                        return CreateContentEpisodeResult::existing(
                            $this->matchingExisting($existing, $data, $fingerprint),
                        );
                    }
                }

                $episode = new ContentEpisode;
                $episode->id = $data->id ?? (string) Str::uuid();
                $episode->user_id = $data->userId;
                $episode->convolab_user_id = $data->convoLabUserId;
                $episode->source_system = ContentSourceSystem::LEARNING_OS;
                $episode->title = $data->title;
                $episode->source_text = $data->sourceText;
                $episode->target_language = $data->targetLanguage;
                $episode->native_language = $data->nativeLanguage;
                $episode->content_type = 'dialogue';
                $episode->jlpt_level = $data->jlptLevel;
                $episode->auto_generate_audio = $data->autoGenerateAudio;
                $episode->status = 'draft';
                $episode->is_sample_content = false;
                $episode->audio_speed = $data->audioSpeed;
                $episode->creation_fingerprint = $fingerprint;
                $episode->save();

                return CreateContentEpisodeResult::created($episode);
            });
        } catch (QueryException $exception) {
            // Cooperative ConvoLab writers serialize on ContentSourceLock. Retain PK recovery for
            // imports, maintenance code, or older deployments that can write without that lock.
            if ($data->id === null || ! IntegrityConstraintViolation::matchesPrimaryKey($exception, 'content_episodes')) {
                throw $exception;
            }

            $this->assertNotTombstoned($data);
            $existing = ContentEpisode::query()->find($data->id);
            if (! $existing instanceof ContentEpisode) {
                throw $exception;
            }

            return CreateContentEpisodeResult::existing($this->matchingExisting($existing, $data, $fingerprint));
        }

        return $result;
    }

    private function matchingExisting(
        ContentEpisode $episode,
        CreateContentEpisodeData $data,
        string $fingerprint,
    ): ContentEpisode {
        if ($episode->user_id !== $data->userId
            || ! hash_equals($episode->convolab_user_id, $data->convoLabUserId)) {
            throw ContentCreationConflictException::conflict($episode->user_id, $episode->convolab_user_id);
        }

        if (! is_string($episode->creation_fingerprint)
            || ! hash_equals($episode->creation_fingerprint, $fingerprint)) {
            throw ContentCreationConflictException::conflict($episode->user_id, $episode->convolab_user_id);
        }

        return $episode;
    }

    private function assertNotTombstoned(CreateContentEpisodeData $data, bool $lock = false): void
    {
        $query = ContentEpisodeTombstone::query()->whereKey($data->id);
        $tombstone = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($tombstone instanceof ContentEpisodeTombstone) {
            throw ContentCreationConflictException::gone($tombstone->user_id, $tombstone->convolab_user_id);
        }
    }
}
