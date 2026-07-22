<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Data\AdminCourseExchangeCollection;
use App\Domain\Admin\Exceptions\AdminCourseScriptConfigurationException;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\AdminCourseScriptGenerator;
use App\Domain\Admin\Support\AdminCourseFirstEpisodeFinder;
use App\Domain\Admin\Support\LegacyJavaScriptValue;
use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentCourseCoreItem;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Models\ContentEpisodeCourse;
use App\Domain\Content\Support\ContentCourseId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ContentSourceSystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class GenerateAdminCourseScriptAction
{
    public function __construct(
        private AdminCourseFirstEpisodeFinder $episodes,
        private AdminCourseScriptGenerator $generator,
    ) {}

    /** @return array{scriptUnits: list<array<string, float|string>>, estimatedDurationSeconds: int, vocabularyItemCount: int} */
    public function handle(string $courseId): array
    {
        $courseId = ContentCourseId::normalize($courseId);
        $course = ContentCourse::query()->find($courseId);
        if (! $course instanceof ContentCourse) {
            throw AdminMutationException::courseNotFound();
        }

        try {
            $exchanges = AdminCourseExchangeCollection::fromPipeline($course->script_json);
        } catch (InvalidArgumentException $exception) {
            throw AdminMutationException::dialogueExchangesRequired($exception);
        }
        $episode = $this->episodes->find($courseId);
        $snapshot = $this->snapshot($course, $episode);

        try {
            $result = $this->generator->generate(
                $course,
                $this->episodeTitle($course, $episode),
                $exchanges,
            );
        } catch (AdminCourseScriptConfigurationException $exception) {
            throw AdminMutationException::invalidCourseScriptConfiguration($exception);
        } catch (InvalidArgumentException $exception) {
            report($exception);

            throw AdminMutationException::invalidCourseScriptResponse($exception);
        }

        DB::transaction(function () use ($courseId, $snapshot, $exchanges, $result): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $course = ContentCourse::query()->whereKey($courseId)->lockForUpdate()->first();
            if (! $course instanceof ContentCourse) {
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

            if (! $this->snapshotMatches($snapshot, $course, $episode)) {
                throw AdminMutationException::courseChangedDuringScriptGeneration();
            }

            $scriptUnits = $result->payload();
            $course->script_json = [
                '_pipelineStage' => 'script',
                '_exchanges' => $exchanges->exchanges,
                '_scriptUnits' => $scriptUnits,
            ];
            $course->script_units_json = $scriptUnits;
            $course->approx_duration_seconds = $result->estimatedDurationSeconds;
            $course->audio_url = null;
            $course->timing_data = null;
            $course->source_system = ContentSourceSystem::LEARNING_OS;
            $course->save();

            $course->coreItems()->delete();
            foreach ($exchanges->coreItems as $item) {
                $coreItem = new ContentCourseCoreItem;
                $coreItem->id = (string) Str::uuid();
                $coreItem->course_id = $course->id;
                $coreItem->text_l2 = $item['textL2'];
                $coreItem->reading_l2 = $item['readingL2'];
                $coreItem->translation_l1 = $item['translationL1'];
                $coreItem->complexity_score = $item['complexityScore'];
                $coreItem->source_episode_id = $episode?->id;
                $coreItem->source_sentence_id = null;
                $coreItem->source_unit_index = null;
                $coreItem->components = null;
                $coreItem->save();
            }
        });

        return [
            'scriptUnits' => $result->payload(),
            'estimatedDurationSeconds' => $result->estimatedDurationSeconds,
            'vocabularyItemCount' => count($exchanges->coreItems),
        ];
    }

    /** @return array{courseUpdatedAt: string, scriptHash: string, episodeId: ?string, episodeUpdatedAt: ?string} */
    private function snapshot(ContentCourse $course, ?ContentEpisode $episode): array
    {
        return [
            'courseUpdatedAt' => (string) $course->getRawOriginal('updated_at'),
            'scriptHash' => hash('sha256', json_encode($course->script_json, JSON_THROW_ON_ERROR)),
            'episodeId' => $episode?->id,
            'episodeUpdatedAt' => $episode === null ? null : (string) $episode->getRawOriginal('updated_at'),
        ];
    }

    /** @param array{courseUpdatedAt: string, scriptHash: string, episodeId: ?string, episodeUpdatedAt: ?string} $snapshot */
    private function snapshotMatches(array $snapshot, ContentCourse $course, ?ContentEpisode $episode): bool
    {
        return (string) $course->getRawOriginal('updated_at') === $snapshot['courseUpdatedAt']
            && hash('sha256', json_encode($course->script_json, JSON_THROW_ON_ERROR)) === $snapshot['scriptHash']
            && $episode?->id === $snapshot['episodeId']
            && ($episode === null ? null : (string) $episode->getRawOriginal('updated_at'))
                === $snapshot['episodeUpdatedAt'];
    }

    private function episodeTitle(ContentCourse $course, ?ContentEpisode $episode): string
    {
        return LegacyJavaScriptValue::isTruthy($episode?->title) ? (string) $episode->title : (string) $course->title;
    }
}
