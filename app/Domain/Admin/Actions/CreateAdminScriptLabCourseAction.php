<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\CreateAdminScriptLabCourseData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Auth\Actions\ResolveConvoLabUserAction;
use App\Domain\Content\Actions\CreateContentEpisodeAction;
use App\Domain\Content\Actions\PromoteContentEpisodeOwnershipAction;
use App\Domain\Content\Data\CreateContentEpisodeData;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseDefaults;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateAdminScriptLabCourseAction
{
    private const SPEAKER_1_VOICE_JA = 'fishaudio:0dff3f6860294829b98f8c4501b2cf25';

    private const SPEAKER_2_VOICE_JA = 'fishaudio:72416f3ff95541d9a2456b945e8a7c32';

    public function __construct(
        private ResolveConvoLabUserAction $resolveUser,
        private CreateContentEpisodeAction $createEpisode,
        private PromoteContentEpisodeOwnershipAction $promoteEpisodeOwnership,
    ) {}

    public function handle(
        string $actorConvoLabUserId,
        CreateAdminScriptLabCourseData $data,
    ): ContentCourse {
        $user = $this->resolveUser->handle($actorConvoLabUserId);
        $convoLabUserId = (string) $user->convolab_id;

        return DB::transaction(function () use ($user, $convoLabUserId, $data): ContentCourse {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $user = User::query()
                ->whereKey($user->getKey())
                ->where('convolab_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if (! $user instanceof User) {
                throw AdminMutationException::userNotFound();
            }

            $episode = $data->episodeId === null
                ? $this->createEpisode->handle(CreateContentEpisodeData::fromInput(
                    (int) $user->getKey(),
                    $convoLabUserId,
                    $data->title,
                    $data->sourceText,
                    $data->targetLanguage,
                    $data->nativeLanguage,
                    jlptLevel: $data->jlptLevel,
                    autoGenerateAudio: false,
                ))
                : ContentEpisode::query()
                    ->whereKey($data->episodeId)
                    ->where('user_id', $user->getKey())
                    ->where('convolab_user_id', $convoLabUserId)
                    ->whereDoesntHave(
                        'courseEpisodes.course',
                        static fn ($query) => $query->where('is_test_course', false),
                    )
                    ->lockForUpdate()
                    ->first();

            if (! $episode instanceof ContentEpisode) {
                throw AdminMutationException::scriptLabEpisodeNotFound();
            }

            $this->promoteEpisodeOwnership->handle(DB::connection(), [$episode]);

            $course = new ContentCourse;
            $course->id = (string) Str::uuid();
            $course->user_id = $user->getKey();
            $course->convolab_user_id = $convoLabUserId;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->title = '[TEST] '.$data->title;
            $course->description = 'Test course for Script Lab: '.$data->title;
            $course->status = 'draft';
            $course->is_sample_content = false;
            $course->is_test_course = true;
            $course->native_language = $data->nativeLanguage;
            $course->target_language = $data->targetLanguage;
            $course->max_lesson_duration_minutes = $data->maxDurationMinutes;
            $course->l1_voice_id = ContentCourseDefaults::NARRATOR_VOICE_EN;
            $course->l1_voice_provider = 'fishaudio';
            $course->jlpt_level = $data->jlptLevel;
            $course->speaker1_gender = $data->speaker1Gender;
            $course->speaker2_gender = $data->speaker2Gender;
            $course->speaker1_voice_id = self::SPEAKER_1_VOICE_JA;
            $course->speaker1_voice_provider = 'fishaudio';
            $course->speaker2_voice_id = self::SPEAKER_2_VOICE_JA;
            $course->speaker2_voice_provider = 'fishaudio';
            $course->save();

            $link = new ContentEpisodeCourse;
            $link->id = (string) Str::uuid();
            $link->episode_id = $episode->id;
            $link->convolab_course_id = $course->id;
            $link->sort_order = 0;
            $link->source_system = ContentSourceSystem::LEARNING_OS;
            $link->save();

            return $course;
        });
    }
}
