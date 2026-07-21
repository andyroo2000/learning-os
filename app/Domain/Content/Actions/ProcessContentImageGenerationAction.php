<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentDialogue;
use App\Domain\Content\Models\ContentImage;
use App\Domain\Content\Models\ContentImageGenerationJob;
use App\Domain\Content\Services\ContentDialogueImagePlanner;
use App\Domain\Content\Support\ContentImageGeneration;
use App\Domain\Content\Support\ContentImageGenerationJobId;
use App\Domain\Content\Support\ContentImagePayload;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ProcessContentImageGenerationAction
{
    public function __construct(private readonly ContentDialogueImagePlanner $planner) {}

    public function handle(string $jobId): void
    {
        $jobId = ContentImageGenerationJobId::normalize($jobId);
        $claimed = $this->claim($jobId);
        if ($claimed === null) {
            return;
        }

        try {
            $images = $this->planner->plan(
                $claimed['sourceText'],
                $claimed['targetLanguage'],
                $claimed['sentences'],
                $claimed['imageCount'],
            );
            $this->complete($jobId, $claimed['claimToken'], $images);
        } catch (Throwable $exception) {
            try {
                $this->releaseClaim($jobId, $claimed['claimToken']);
            } catch (Throwable $releaseException) {
                report($releaseException);
            }

            throw $exception;
        }
    }

    /** @return null|array{claimToken: string, sourceText: string, targetLanguage: string, imageCount: int, sentences: list<array{id: string, text: string}>} */
    private function claim(string $jobId): ?array
    {
        return DB::transaction(function () use ($jobId): ?array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentImageGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null || ContentImageGeneration::isTerminal($job->state)) {
                return null;
            }
            if ($job->state === ContentImageGeneration::STATE_ACTIVE
                && $job->started_at !== null
                && $job->started_at->isAfter(now()->subSeconds(ContentImageGeneration::ACTIVE_STALE_AFTER_SECONDS))) {
                return null;
            }

            $dialogue = $this->ownedDialogue($job);
            if ($dialogue === null) {
                $this->markFailed($job);

                return null;
            }

            $sentences = $dialogue->sentences()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'text'])
                ->map(fn ($sentence): array => ['id' => (string) $sentence->id, 'text' => (string) $sentence->text])
                ->all();

            $claimToken = (string) Str::uuid();
            $job->state = ContentImageGeneration::STATE_ACTIVE;
            $job->progress = 10;
            $job->claim_token = $claimToken;
            $job->started_at = now();
            $job->save();

            return [
                'claimToken' => $claimToken,
                'sourceText' => (string) $dialogue->episode->source_text,
                'targetLanguage' => (string) $dialogue->episode->target_language,
                'imageCount' => (int) $job->image_count,
                'sentences' => $sentences,
            ];
        });
    }

    private function releaseClaim(string $jobId, string $claimToken): void
    {
        DB::transaction(function () use ($claimToken, $jobId): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentImageGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null
                || $job->state !== ContentImageGeneration::STATE_ACTIVE
                || $job->claim_token !== $claimToken) {
                return;
            }

            $job->state = ContentImageGeneration::STATE_WAITING;
            $job->progress = 0;
            $job->claim_token = null;
            $job->started_at = null;
            $job->save();
        });
    }

    /** @param list<array{prompt: string, order: int, sentenceStartId: string, sentenceEndId: string, url: string}> $plannedImages */
    private function complete(string $jobId, string $claimToken, array $plannedImages): void
    {
        DB::transaction(function () use ($claimToken, $jobId, $plannedImages): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $job = ContentImageGenerationJob::query()->whereKey($jobId)->lockForUpdate()->first();
            if ($job === null
                || $job->state !== ContentImageGeneration::STATE_ACTIVE
                || $job->claim_token !== $claimToken) {
                return;
            }
            if ($this->ownedDialogue($job) === null) {
                $this->markFailed($job);

                return;
            }

            ContentImage::query()->where('episode_id', $job->episode_id)->delete();
            $result = [];
            foreach ($plannedImages as $planned) {
                $image = new ContentImage;
                $image->id = (string) Str::uuid();
                $image->episode_id = $job->episode_id;
                $image->url = $planned['url'];
                $image->prompt = $planned['prompt'];
                $image->sort_order = $planned['order'];
                $image->sentence_start_id = $planned['sentenceStartId'];
                $image->sentence_end_id = $planned['sentenceEndId'];
                $image->created_at = now();
                $image->save();

                $result[] = ContentImagePayload::fromModel($image);
            }

            $job->state = ContentImageGeneration::STATE_COMPLETED;
            $job->progress = 100;
            $job->claim_token = null;
            $job->result = $result;
            $job->error_message = null;
            $job->finished_at = now();
            $job->save();
        });
    }

    private function ownedDialogue(ContentImageGenerationJob $job): ?ContentDialogue
    {
        return ContentDialogue::query()
            ->whereKey($job->dialogue_id)
            ->where('episode_id', $job->episode_id)
            ->whereHas('episode', fn ($query) => $query
                ->where('user_id', $job->user_id)
                ->where('convolab_user_id', $job->convolab_user_id))
            ->with('episode')
            ->lockForUpdate()
            ->first();
    }

    private function markFailed(ContentImageGenerationJob $job): void
    {
        $job->state = ContentImageGeneration::STATE_FAILED;
        $job->claim_token = null;
        $job->error_message = ContentImageGeneration::FAILED_MESSAGE;
        $job->finished_at = now();
        $job->save();
    }
}
