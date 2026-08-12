<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreateContentCourseData;
use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Exceptions\ContentCreationConflictException;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseTombstone;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Results\CreateContentCourseResult;
use App\Domain\Content\Services\ContentCourseDescriptionGenerator;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentCreationFingerprint;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CreateContentCourseAction
{
    public function __construct(
        private readonly CreateContentEpisodeAction $createEpisode,
        private readonly PromoteContentEpisodeOwnershipAction $promoteEpisodeOwnership,
        private readonly ContentCourseDescriptionGenerator $descriptionGenerator,
    ) {}

    public function handle(CreateContentCourseData $data): CreateContentCourseResult
    {
        $fingerprint = $data->id === null ? null : ContentCreationFingerprint::course($data);
        if ($data->id !== null) {
            $this->assertNotTombstoned($data);
            $existing = ContentCourse::query()->find($data->id);
            if ($existing instanceof ContentCourse) {
                return CreateContentCourseResult::existing($this->matchingExisting($existing, $data, $fingerprint));
            }
        }

        $episodeTitles = [];
        $descriptionGenerationToken = $data->description === null ? (string) Str::uuid() : null;
        try {
            $result = DB::transaction(function () use (
                $data,
                $fingerprint,
                &$episodeTitles,
                $descriptionGenerationToken,
            ): CreateContentCourseResult {
                ContentSourceLock::acquireConvoLab(DB::connection());

                if ($data->id !== null) {
                    $this->assertNotTombstoned($data, true);
                    $existing = ContentCourse::query()->whereKey($data->id)->lockForUpdate()->first();
                    if ($existing instanceof ContentCourse) {
                        return CreateContentCourseResult::existing($this->matchingExisting($existing, $data, $fingerprint));
                    }
                }

                $episodes = $data->sourceText !== null
                    ? [$this->createInlineEpisode($data)]
                    : $this->findOwnedEpisodes($data);

                if ($episodes === null) {
                    return CreateContentCourseResult::episodesNotFound();
                }

                $this->promoteEpisodeOwnership->handle(DB::connection(), $episodes);

                $episodeTitles = array_map(
                    static fn (ContentEpisode $episode): string => $episode->title,
                    $episodes,
                );

                $course = new ContentCourse;
                $course->id = $data->id ?? (string) Str::uuid();
                $course->user_id = $data->userId;
                $course->convolab_user_id = $data->convoLabUserId;
                $course->source_system = ContentSourceSystem::LEARNING_OS;
                $course->title = $data->title;
                $course->description = $data->description
                    ?? ContentCourseDefaults::description($data->targetLanguage);
                $course->status = 'draft';
                $course->is_sample_content = false;
                $course->is_test_course = false;
                $course->native_language = $data->nativeLanguage;
                $course->target_language = $data->targetLanguage;
                $course->max_lesson_duration_minutes = $data->maxLessonDurationMinutes;
                $course->l1_voice_id = $data->l1VoiceId;
                $course->l1_voice_provider = ContentCourseDefaults::voiceProvider($data->l1VoiceId);
                $course->jlpt_level = $data->jlptLevel;
                $course->speaker1_gender = $data->speaker1Gender;
                $course->speaker2_gender = $data->speaker2Gender;
                $course->speaker1_voice_id = $data->speaker1VoiceId;
                $course->speaker1_voice_provider = ContentCourseDefaults::voiceProvider($data->speaker1VoiceId);
                $course->speaker2_voice_id = $data->speaker2VoiceId;
                $course->speaker2_voice_provider = ContentCourseDefaults::voiceProvider($data->speaker2VoiceId);
                $course->creation_fingerprint = $fingerprint;
                $course->description_generation_token = $descriptionGenerationToken;
                $course->save();

                foreach ($episodes as $sortOrder => $episode) {
                    $link = new ContentEpisodeCourse;
                    $link->id = (string) Str::uuid();
                    $link->episode_id = $episode->id;
                    $link->convolab_course_id = $course->id;
                    $link->sort_order = $sortOrder;
                    $link->source_system = ContentSourceSystem::LEARNING_OS;
                    $link->save();
                }

                return CreateContentCourseResult::created($course);
            });
        } catch (QueryException $exception) {
            if ($data->id === null || ! IntegrityConstraintViolation::matchesPrimaryKey($exception, 'content_courses')) {
                throw $exception;
            }

            $this->assertNotTombstoned($data);
            $existing = ContentCourse::query()->find($data->id);
            if (! $existing instanceof ContentCourse) {
                throw $exception;
            }

            $result = CreateContentCourseResult::existing($this->matchingExisting($existing, $data, $fingerprint));
        }

        if ($result->wasCreated && $result->course !== null && $data->description === null) {
            try {
                $description = $this->descriptionGenerator->generate(
                    $episodeTitles,
                    strtoupper($data->targetLanguage),
                    strtoupper($data->nativeLanguage),
                );
            } catch (Throwable $e) {
                // Course creation remains available when optional description generation fails.
                report($e);
                $description = null;
            }

            $courseId = $result->course->id;
            $course = DB::transaction(function () use (
                $courseId,
                $description,
                $descriptionGenerationToken,
            ): ?ContentCourse {
                ContentSourceLock::acquireConvoLab(DB::connection());

                $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
                if (! $course instanceof ContentCourse) {
                    return null;
                }

                if (is_string($course->description_generation_token)
                    && hash_equals($course->description_generation_token, $descriptionGenerationToken)) {
                    if ($description !== null) {
                        $course->description = $description;
                    }
                    $course->description_generation_token = null;
                    $course->save();
                }

                return $course;
            });

            if ($course instanceof ContentCourse) {
                $result = CreateContentCourseResult::created($course);
            }
        }

        return $result;
    }

    private function createInlineEpisode(CreateContentCourseData $data): ContentEpisode
    {
        $sourceText = $data->sourceText;
        if ($sourceText === null) {
            throw new \LogicException('Inline Course creation requires source text.');
        }

        return $this->createEpisode->handle(CreateContentEpisodeData::fromInput(
            userId: $data->userId,
            convoLabUserId: $data->convoLabUserId,
            title: $data->title,
            sourceText: $sourceText,
            targetLanguage: $data->targetLanguage,
            nativeLanguage: $data->nativeLanguage,
            jlptLevel: $data->jlptLevel,
        ))->episode;
    }

    /** @return list<ContentEpisode>|null */
    private function findOwnedEpisodes(CreateContentCourseData $data): ?array
    {
        $episodes = ContentEpisode::query()
            ->whereIn('id', $data->episodeIds)
            ->where('user_id', $data->userId)
            ->where('convolab_user_id', $data->convoLabUserId)
            ->lockForUpdate()
            ->get(['id', 'title', 'source_system'])
            ->keyBy('id');

        if ($episodes->count() !== count($data->episodeIds)) {
            return null;
        }

        $orderedEpisodes = [];
        foreach ($data->episodeIds as $episodeId) {
            $episode = $episodes->get($episodeId);
            if (! $episode instanceof ContentEpisode) {
                throw new \LogicException('Owned Course Episode lookup returned an incomplete result.');
            }
            $orderedEpisodes[] = $episode;
        }

        return $orderedEpisodes;
    }

    private function matchingExisting(
        ContentCourse $course,
        CreateContentCourseData $data,
        string $fingerprint,
    ): ContentCourse {
        if ($course->user_id !== $data->userId
            || ! hash_equals($course->convolab_user_id, $data->convoLabUserId)) {
            throw ContentCreationConflictException::conflict($course->user_id, $course->convolab_user_id);
        }

        if (! is_string($course->creation_fingerprint)
            || ! hash_equals($course->creation_fingerprint, $fingerprint)) {
            throw ContentCreationConflictException::conflict($course->user_id, $course->convolab_user_id);
        }

        return $course;
    }

    private function assertNotTombstoned(CreateContentCourseData $data, bool $lock = false): void
    {
        $query = ContentCourseTombstone::query()->whereKey($data->id);
        $tombstone = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($tombstone instanceof ContentCourseTombstone) {
            throw ContentCreationConflictException::gone($tombstone->user_id, $tombstone->convolab_user_id);
        }
    }
}
