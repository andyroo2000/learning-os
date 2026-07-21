<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\GenerateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Models\ContentAudioScriptGenerationJob;
use App\Domain\Content\Support\ContentAudioScriptGeneration;
use App\Domain\Content\Support\ContentAudioScriptJob;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StartContentAudioScriptGenerationAction
{
    public function __construct(
        private ShowContentAudioScriptAction $show,
        private PromoteContentEpisodeOwnershipAction $promote,
    ) {}

    /** @param callable(string): void $afterCommit */
    public function handle(
        int $userId,
        string $convoLabUserId,
        string $episodeId,
        GenerateContentAudioScriptData $data,
        callable $afterCommit,
    ): ContentAudioScriptGenerationJob {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        return DB::transaction(function () use (
            $afterCommit,
            $convoLabUserId,
            $data,
            $episodeId,
            $userId,
        ): ContentAudioScriptGenerationJob {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $script = $this->show->locked($userId, $convoLabUserId, $episodeId);

            $existing = ContentAudioScriptGenerationJob::query()
                ->where('script_id', $script->id)
                ->where('kind', $data->kind)
                ->whereIn('state', [ContentAudioScriptJob::STATE_WAITING, ContentAudioScriptJob::STATE_ACTIVE])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->input !== $data->toArray()) {
                    throw new ContentAudioScriptConflictException('Different script generation is already in progress.');
                }

                return $existing;
            }

            $activeRenderExists = ContentAudioScriptGenerationJob::query()
                ->where('script_id', $script->id)
                ->where('kind', ContentAudioScriptJob::KIND_RENDER)
                ->whereIn('state', [ContentAudioScriptJob::STATE_WAITING, ContentAudioScriptJob::STATE_ACTIVE])
                ->exists();
            if (ContentAudioScriptGeneration::isActive($script) && ! $activeRenderExists) {
                throw new ContentAudioScriptConflictException('Script annotation is already in progress.');
            }
            if (! $script->segments()->exists()) {
                throw new ContentAudioScriptConflictException('Review script segments before starting generation.');
            }

            $this->promote->handle(DB::connection(), [$script->episode]);
            $attemptField = $data->kind === ContentAudioScriptJob::KIND_RENDER
                ? 'render_generation_attempt'
                : 'image_generation_attempt';
            $script->{$attemptField} = ((int) $script->{$attemptField}) + 1;
            if ($data->kind === ContentAudioScriptJob::KIND_RENDER) {
                $script->status = 'generating';
                $script->error_message = null;
                $script->episode->status = 'generating';
                $script->episode->save();
            } else {
                $script->image_status = 'generating';
                $script->image_error_message = null;
            }
            $script->save();

            $job = new ContentAudioScriptGenerationJob;
            $job->id = (string) Str::uuid();
            $job->script_id = $script->id;
            $job->episode_id = $script->episode_id;
            $job->user_id = $userId;
            $job->convolab_user_id = $convoLabUserId;
            $job->kind = $data->kind;
            $job->attempt = $script->{$attemptField};
            $job->state = ContentAudioScriptJob::STATE_WAITING;
            $job->progress = 0;
            $job->input = $data->toArray();
            $job->save();

            DB::afterCommit(static fn () => $afterCommit($job->id));

            return $job;
        });
    }
}
