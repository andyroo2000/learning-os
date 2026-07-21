<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Services\ContentAudioScriptAnnotator;
use App\Domain\Content\Services\ContentAudioScriptMediaCleaner;
use App\Domain\Content\Services\ContentAudioScriptRenderCleaner;
use App\Domain\Content\Support\ContentAudioScriptGeneration;
use App\Domain\Content\Support\ContentEpisodeId;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class AnnotateContentAudioScriptAction
{
    public function __construct(
        private ShowContentAudioScriptAction $show,
        private PromoteContentEpisodeOwnershipAction $promote,
        private ReplaceContentAudioScriptSegmentsAction $replace,
        private ContentAudioScriptAnnotator $annotator,
        private ContentAudioScriptMediaCleaner $mediaCleaner,
        private ContentAudioScriptRenderCleaner $renderCleaner,
    ) {}

    public function handle(int $userId, string $convoLabUserId, string $episodeId): ContentAudioScript
    {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $episodeId = ContentEpisodeId::normalize($episodeId);

        $claim = DB::transaction(function () use ($userId, $convoLabUserId, $episodeId): array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $script = $this->show->locked($userId, $convoLabUserId, $episodeId);
            if ($script->hasGenerationInProgress()) {
                throw new ContentAudioScriptConflictException('Script generation is already in progress.');
            }
            if (ContentAudioScriptGeneration::isActive($script)) {
                throw new ContentAudioScriptConflictException('Script annotation is already in progress.');
            }

            $this->promote->handle(DB::connection(), [$script->episode]);
            $attempt = (string) Str::uuid();
            $script->status = 'generating';
            $script->generation_metadata = ['annotationAttempt' => $attempt];
            $script->error_message = null;
            $script->save();

            return [
                'scriptId' => $script->id,
                'sourceText' => $script->episode->source_text,
                'attempt' => $attempt,
            ];
        });

        try {
            $annotation = $this->annotator->annotate($claim['sourceText']);

            $replacement = DB::transaction(function () use ($claim, $annotation): array {
                ContentSourceLock::acquireConvoLab(DB::connection());
                $script = ContentAudioScript::query()
                    ->whereKey($claim['scriptId'])
                    ->with('episode')
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($script->status !== 'generating'
                    || data_get($script->generation_metadata, 'annotationAttempt') !== $claim['attempt']) {
                    throw new ContentAudioScriptConflictException('Script annotation was superseded.');
                }

                $replacedPaths = $this->replace->handle($script, $annotation['segments']);
                $script->status = 'annotated';
                $script->generation_metadata = ['segmentCount' => count($annotation['segments'])];
                $script->error_message = null;
                $script->save();

                $script->episode->title = $annotation['title'];
                $script->episode->status = 'draft';
                $script->episode->save();

                return ['episodeId' => $script->episode_id, 'paths' => $replacedPaths];
            });
            $this->mediaCleaner->deleteFiles($replacement['paths']['mediaPaths']);
            $this->renderCleaner->deleteFiles($replacement['episodeId'], $replacement['paths']['renderPaths']);
        } catch (Throwable $exception) {
            try {
                $this->fail($claim['scriptId'], $claim['attempt'], $exception);
            } catch (Throwable $failureException) {
                report($failureException);
            }
            throw $exception;
        }

        return ContentAudioScript::query()
            ->whereKey($claim['scriptId'])
            ->with($this->show->relations())
            ->firstOrFail();
    }

    private function fail(string $scriptId, string $attempt, Throwable $exception): void
    {
        DB::transaction(function () use ($scriptId, $attempt, $exception): void {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $script = ContentAudioScript::query()
                ->whereKey($scriptId)
                ->with('episode')
                ->lockForUpdate()
                ->first();
            if ($script === null
                || $script->status !== 'generating'
                || data_get($script->generation_metadata, 'annotationAttempt') !== $attempt) {
                return;
            }

            $script->status = 'error';
            $script->generation_metadata = null;
            $script->error_message = mb_substr($exception->getMessage(), 0, 2_000);
            $script->save();
            $script->episode->status = 'error';
            $script->episode->save();
        });
    }
}
