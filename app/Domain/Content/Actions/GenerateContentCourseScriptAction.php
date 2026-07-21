<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Services\ContentCourseScriptGenerator;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Domain\Content\Support\ConvoLabUserId;
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

        $prepared = DB::transaction(function () use ($userId, $convoLabUserId, $courseId, $expectedAttempt): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($course === null) {
                return null;
            }
            if ($expectedAttempt !== null && ($course->status !== 'generating'
                || (int) $course->generation_attempt !== $expectedAttempt)) {
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

            $episodesById = ContentEpisode::query()
                ->whereIn('id', $links->pluck('episode_id'))
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($episodesById->count() !== $links->count()) {
                throw new RuntimeException('Course generation found an incomplete Episode graph.');
            }

            $episodes = $links->map(function (ContentEpisodeCourse $link) use ($episodesById): ContentEpisode {
                $episode = $episodesById->get($link->episode_id);
                if (! $episode instanceof ContentEpisode) {
                    throw new RuntimeException('Course generation found an incomplete Episode graph.');
                }

                return $episode;
            });
            $this->promoteEpisodeOwnership->handle(DB::connection(), $episodes);

            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->generation_revision = ((int) $course->generation_revision) + 1;
            $course->save();
            ContentEpisodeCourse::query()
                ->whereKey($links->pluck('id'))
                ->update(['source_system' => ContentSourceSystem::LEARNING_OS]);

            $firstEpisode = $episodes->first();
            if (! $firstEpisode instanceof ContentEpisode) {
                throw new RuntimeException('Course generation requires at least one Episode.');
            }

            return [
                'revision' => (int) $course->generation_revision,
                'episodeId' => $firstEpisode->id,
                'snapshot' => $this->generationSnapshot($course, $firstEpisode),
            ];
        });

        if ($prepared === null) {
            return null;
        }

        $generated = $this->generator->generate($prepared['snapshot']);

        return DB::transaction(function () use (
            $userId,
            $convoLabUserId,
            $courseId,
            $expectedAttempt,
            $prepared,
            $generated,
        ): ContentCourse {
            ContentSourceLock::acquireConvoLab(DB::connection());

            $course = ContentCourse::query()
                ->whereKey($courseId)
                ->where('user_id', $userId)
                ->where('convolab_user_id', $convoLabUserId)
                ->lockForUpdate()
                ->first();
            if ($course === null
                || (int) $course->generation_revision !== $prepared['revision']
                || ($expectedAttempt !== null && ($course->status !== 'generating'
                    || (int) $course->generation_attempt !== $expectedAttempt))) {
                throw new RuntimeException('Course changed while its script was being generated.');
            }

            $course->script_json = $generated->pipelinePayload();
            $course->script_units_json = $generated->scriptUnitsPayload();
            $course->approx_duration_seconds = $generated->estimatedDurationSeconds;
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
