<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Services\ContentAudioScriptMediaCleaner;
use App\Domain\Content\Services\ContentAudioScriptRenderCleaner;
use App\Domain\Content\Support\ContentAudioScriptGeneration;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;

final readonly class UpdateContentAudioScriptSegmentsAction
{
    public function __construct(
        private ShowContentAudioScriptAction $show,
        private PromoteContentEpisodeOwnershipAction $promote,
        private ReplaceContentAudioScriptSegmentsAction $replace,
        private ContentAudioScriptMediaCleaner $mediaCleaner,
        private ContentAudioScriptRenderCleaner $renderCleaner,
    ) {}

    public function handle(UpdateContentAudioScriptData $data): ContentAudioScript
    {
        $result = DB::transaction(function () use ($data): array {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $script = $this->show->locked(
                $data->userId,
                $data->convoLabUserId,
                $data->episodeId,
            );
            if ($script->hasGenerationInProgress()) {
                throw new ContentAudioScriptConflictException('Script generation is already in progress.');
            }
            if (ContentAudioScriptGeneration::isActive($script)) {
                throw new ContentAudioScriptConflictException('Script annotation is already in progress.');
            }

            $this->promote->handle(DB::connection(), [$script->episode]);
            $replacedPaths = $this->replace->handle($script, $data->segments);

            $script->status = 'annotated';
            $script->voice_id = $data->voiceId ?? $script->voice_id;
            $script->generation_metadata = null;
            $script->error_message = null;
            $script->save();

            $script->episode->title = ContentAudioScriptInput::title(
                $data->title,
                $script->episode->title,
            );
            $script->episode->status = 'draft';
            $script->episode->save();

            return [
                'scriptId' => $script->id,
                'episodeId' => $script->episode_id,
                'replacedPaths' => $replacedPaths,
            ];
        });
        $this->mediaCleaner->deleteFiles($result['replacedPaths']['mediaPaths']);
        $this->renderCleaner->deleteFiles($result['episodeId'], $result['replacedPaths']['renderPaths']);

        return ContentAudioScript::query()
            ->whereKey($result['scriptId'])
            ->with($this->show->relations())
            ->firstOrFail();
    }
}
