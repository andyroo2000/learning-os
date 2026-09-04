<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Results\ContentCourseScriptGenerationResult;
use App\Domain\Content\Services\ContentCourseScriptGenerator;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GenerateContentCourseScriptAction
{
    public function __construct(
        private readonly PromoteContentEpisodeOwnershipAction $promoteEpisodeOwnership,
        private readonly ContentCourseScriptGenerator $generator,
    ) {}

    public function handle(
        int $userId,
        string $convoLabUserId,
        string $courseId,
        ?int $expectedAttempt = null,
    ): ?ContentCourse {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $courseId = ContentCourseId::normalize($courseId);
        if ($expectedAttempt !== null && $expectedAttempt < 1) {
            throw new InvalidArgumentException('Course generation attempt must be positive.');
        }

        $scope = [
            'userId' => $userId,
            'convoLabUserId' => $convoLabUserId,
            'courseId' => $courseId,
            'expectedAttempt' => $expectedAttempt,
        ];
        $prepared = $this->prepareGeneration($scope);

        if ($prepared === null) {
            return null;
        }

        $generated = $this->generator->generate($prepared['snapshot']);

        return $this->persistGeneration(
            $scope,
            $prepared,
            $generated,
        );
    }

    /**
     * @param  array{userId: int, convoLabUserId: string, courseId: string, expectedAttempt: int|null}  $scope
     * @return array{revision: int, episodeId: string, snapshot: array<string, mixed>}|null
     */
    private function prepareGeneration(array $scope): ?array
    {
        return DB::transaction(function () use ($scope): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = $this->lockedCourse($scope);
            if ($course === null) {
                return null;
            }
            if (! $this->matchesExpectedAttempt($course, $scope['expectedAttempt'])) {
                return null;
            }

            $links = ContentEpisodeCourse::query()
                ->where('convolab_course_id', $course->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($links->isEmpty()) {
                throw new RuntimeException('Course generation requires at least one Episode.');
            }

            $episodes = $this->lockedEpisodes($scope, $links);
            $this->promoteEpisodeOwnership->handle(DB::connection(), $episodes);

            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->generation_revision = ((int) $course->generation_revision) + 1;
            $course->save();
            ContentEpisodeCourse::query()
                ->whereKey($links->pluck('id'))
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);

            $firstEpisode = $this->firstEpisode($episodes);

            return [
                'revision' => (int) $course->generation_revision,
                'episodeId' => $firstEpisode->id,
                'snapshot' => $this->generationSnapshot($course, $firstEpisode),
            ];
        });
    }

    /**
     * @param  array{userId: int, convoLabUserId: string, courseId: string, expectedAttempt: int|null}  $scope
     * @param  array{revision: int, episodeId: string, snapshot: array<string, mixed>}  $prepared
     */
    private function persistGeneration(
        array $scope,
        array $prepared,
        ContentCourseScriptGenerationResult $generated,
    ): ContentCourse {
        return DB::transaction(function () use (
            $scope,
            $prepared,
            $generated,
        ): ContentCourse {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = $this->lockedCourseForPersistence($scope, $prepared['revision']);

            $course->script_json = $generated->pipelinePayload();
            $course->script_units_json = $generated->scriptUnitsPayload();
            $course->approx_duration_seconds = $generated->estimatedDurationSeconds;
            if ($scope['expectedAttempt'] !== null) {
                $course->generation_stage = 'audio';
                $course->generation_progress = 60;
                $course->generation_heartbeat_at = now();
            }
            $course->save();

            $course->coreItems()->delete();
            foreach ($generated->coreItems as $item) {
                $coreItem = new ContentCourseCoreItem;
                $coreItem->id = (string) Str::uuid();
                $coreItem->course_id = $course->id;
                $coreItem->text_l2 = $item['textL2'];
                $coreItem->reading_l2 = $item['readingL2'];
                $coreItem->translation_l1 = $item['translationL1'];
                $coreItem->complexity_score = $item['complexityScore'];
                $coreItem->source_episode_id = $prepared['episodeId'];
                $coreItem->source_sentence_id = null;
                $coreItem->source_unit_index = $item['sourceUnitIndex'];
                $coreItem->components = $item['components'];
                $coreItem->save();
            }

            return $course->load('coreItems');
        });
    }

    /**
     * Caller owns the transaction and acquires the shared ConvoLab source lock first.
     *
     * @param  array{userId: int, convoLabUserId: string, courseId: string, expectedAttempt: int|null}  $scope
     */
    private function lockedCourse(array $scope): ?ContentCourse
    {
        return ContentCourse::query()
            ->whereKey($scope['courseId'])
            ->where('user_id', $scope['userId'])
            ->where('convolab_user_id', $scope['convoLabUserId'])
            ->lockForUpdate()
            ->first();
    }

    /**
     * Caller owns the transaction and has already locked the Course and Episode links.
     *
     * @param  array{userId: int, convoLabUserId: string, courseId: string, expectedAttempt: int|null}  $scope
     * @param  Collection<int, ContentEpisodeCourse>  $links
     * @return Collection<int, ContentEpisode>
     */
    private function lockedEpisodes(array $scope, Collection $links): Collection
    {
        $episodesById = ContentEpisode::query()
            ->whereIn('id', $links->pluck('episode_id'))
            ->where('user_id', $scope['userId'])
            ->where('convolab_user_id', $scope['convoLabUserId'])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($episodesById->count() !== $links->count()) {
            throw new RuntimeException('Course generation found an incomplete Episode graph.');
        }

        return $links->map(function (ContentEpisodeCourse $link) use ($episodesById): ContentEpisode {
            $episode = $episodesById->get($link->episode_id);
            if (! $episode instanceof ContentEpisode) {
                throw new RuntimeException('Course generation found an incomplete Episode graph.');
            }

            return $episode;
        });
    }

    /** @param Collection<int, ContentEpisode> $episodes */
    private function firstEpisode(Collection $episodes): ContentEpisode
    {
        $firstEpisode = $episodes->first();
        if (! $firstEpisode instanceof ContentEpisode) {
            throw new RuntimeException('Course generation requires at least one Episode.');
        }

        return $firstEpisode;
    }

    /** @param array{userId: int, convoLabUserId: string, courseId: string, expectedAttempt: int|null} $scope */
    private function lockedCourseForPersistence(array $scope, int $revision): ContentCourse
    {
        $course = $this->lockedCourse($scope);
        if ($course === null) {
            throw new RuntimeException('Course changed while its script was being generated.');
        }
        if ((int) $course->generation_revision !== $revision) {
            throw new RuntimeException('Course changed while its script was being generated.');
        }
        if (! $this->matchesExpectedAttempt($course, $scope['expectedAttempt'])) {
            throw new RuntimeException('Course changed while its script was being generated.');
        }

        return $course;
    }

    private function matchesExpectedAttempt(ContentCourse $course, ?int $expectedAttempt): bool
    {
        return $expectedAttempt === null
            || ($course->status === 'generating' && (int) $course->generation_attempt === $expectedAttempt);
    }

    /** @return array<string, mixed> */
    private function generationSnapshot(ContentCourse $course, ContentEpisode $episode): array
    {
        $episode->load([
            'dialogue.speakers',
            'dialogue.sentences' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'dialogue.sentences.speaker',
        ]);

        $dialogue = $episode->dialogue;
        $sentences = $dialogue?->sentences->map(static fn ($sentence): array => [
            'speakerName' => $sentence->speaker?->name,
            'speakerVoiceId' => $sentence->speaker?->voice_id,
            'textL2' => $sentence->text,
            'translationL1' => $sentence->translation,
            'readingL2' => $this->sentenceReading($sentence->metadata),
        ])->values()->all() ?? [];

        return [
            'course' => [
                'title' => $course->title,
                'nativeLanguage' => $course->native_language,
                'targetLanguage' => $course->target_language,
                'jlptLevel' => $course->jlpt_level,
                'maxLessonDurationMinutes' => $course->max_lesson_duration_minutes,
                'l1VoiceId' => $course->l1_voice_id,
                'speaker1VoiceId' => $course->speaker1_voice_id,
                'speaker2VoiceId' => $course->speaker2_voice_id,
            ],
            'episode' => [
                'title' => $episode->title,
                'sourceText' => $episode->source_text,
                'sentences' => $sentences,
            ],
        ];
    }

    private function sentenceReading(mixed $metadata): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }
        $japanese = $metadata['japanese'] ?? null;
        if (is_array($japanese)) {
            foreach (['furigana', 'kana'] as $key) {
                if (is_string($japanese[$key] ?? null) && trim($japanese[$key]) !== '') {
                    return trim($japanese[$key]);
                }
            }
        }

        return is_string($metadata['reading'] ?? null) && trim($metadata['reading']) !== ''
            ? trim($metadata['reading'])
            : null;
    }
}
