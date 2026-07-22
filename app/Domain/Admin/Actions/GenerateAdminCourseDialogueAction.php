<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\GenerateAdminCourseDialogueData;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\AdminCourseDialogueGenerator;
use App\Domain\Admin\Services\AdminCourseDialoguePromptBuilder;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Models\ContentSpeaker;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class GenerateAdminCourseDialogueAction
{
    private const DEFAULT_SPEAKER_1_VOICE = 'fishaudio:0dff3f6860294829b98f8c4501b2cf25';

    private const DEFAULT_SPEAKER_2_VOICE = 'fishaudio:72416f3ff95541d9a2456b945e8a7c32';

    public function __construct(
        private AdminCourseDialoguePromptBuilder $prompts,
        private AdminCourseDialogueGenerator $generator,
    ) {}

    /** @return list<array<string, mixed>> */
    public function handle(string $courseId, GenerateAdminCourseDialogueData $data): array
    {
        $courseId = ContentCourseId::normalize($courseId);
        $course = ContentCourse::query()->find($courseId);
        if ($course === null) {
            throw AdminMutationException::courseNotFound();
        }

        $episode = $this->firstEpisode($courseId);
        if ($episode === null || (string) $episode->source_text === '') {
            throw AdminMutationException::courseSourceRequired();
        }

        $prompt = $data->customPrompt ?? $this->prompts->build($course, $episode)['prompt'];
        $snapshot = [
            'courseUpdatedAt' => (string) $course->getRawOriginal('updated_at'),
            'episodeId' => (string) $episode->id,
            'episodeUpdatedAt' => (string) $episode->getRawOriginal('updated_at'),
        ];

        try {
            $result = $this->generator->generate(
                $prompt,
                $this->existingVoices($episode->id),
                $this->voice($course->speaker1_voice_id, self::DEFAULT_SPEAKER_1_VOICE),
                $this->voice($course->speaker2_voice_id, self::DEFAULT_SPEAKER_2_VOICE),
            );
        } catch (InvalidArgumentException $exception) {
            report($exception);

            throw AdminMutationException::invalidDialogueResponse($exception);
        }

        DB::transaction(function () use ($courseId, $snapshot, $result): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if ($course === null) {
                throw AdminMutationException::courseNotFound();
            }
            $link = ContentEpisodeCourse::query()
                ->where('convolab_course_id', $courseId)
                ->orderBy('sort_order')
                ->orderBy('episode_id')
                ->lockForUpdate()
                ->first();
            $episode = $link === null
                ? null
                : ContentEpisode::query()->whereKey($link->episode_id)->lockForUpdate()->first();

            if ((string) $course->getRawOriginal('updated_at') !== $snapshot['courseUpdatedAt']
                || $episode === null
                || (string) $episode->id !== $snapshot['episodeId']
                || (string) $episode->getRawOriginal('updated_at') !== $snapshot['episodeUpdatedAt']) {
                throw AdminMutationException::courseChangedDuringGeneration();
            }

            $course->script_json = [
                '_pipelineStage' => 'exchanges',
                '_exchanges' => $result->exchanges,
            ];
            $course->audio_url = null;
            $course->timing_data = null;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->save();
        });

        return $result->exchanges;
    }

    private function firstEpisode(string $courseId): ?ContentEpisode
    {
        return ContentEpisode::query()
            ->join('content_episode_courses', 'content_episode_courses.episode_id', '=', 'content_episodes.id')
            ->where('content_episode_courses.convolab_course_id', $courseId)
            ->orderBy('content_episode_courses.sort_order')
            ->orderBy('content_episodes.id')
            ->select('content_episodes.*')
            ->first();
    }

    /** @return list<array{speakerName: string, voiceId: string}> */
    private function existingVoices(string $episodeId): array
    {
        return ContentSpeaker::query()
            ->join('content_dialogues', 'content_dialogues.id', '=', 'content_speakers.dialogue_id')
            ->where('content_dialogues.episode_id', $episodeId)
            ->orderBy('content_speakers.name')
            ->get(['content_speakers.name', 'content_speakers.voice_id'])
            ->map(static fn (ContentSpeaker $speaker): array => [
                'speakerName' => (string) $speaker->name,
                'voiceId' => (string) $speaker->voice_id,
            ])
            ->all();
    }

    private function voice(mixed $value, string $fallback): string
    {
        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
