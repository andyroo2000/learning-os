<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\UpdateContentAudioScriptData;
use App\Domain\Content\Exceptions\ContentAudioScriptConflictException;
use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;

final readonly class UpdateContentAudioScriptSegmentsAction
{
    public function __construct(
        private ShowContentAudioScriptAction $show,
        private PromoteContentEpisodeOwnershipAction $promote,
        private ReplaceContentAudioScriptSegmentsAction $replace,
    ) {}

    public function handle(UpdateContentAudioScriptData $data): ContentAudioScript
    {
        $scriptId = DB::transaction(function () use ($data): string {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $script = $this->show->locked(
                $data->userId,
                $data->convoLabUserId,
                $data->episodeId,
            );
            if ($script->status === 'generating') {
                throw new ContentAudioScriptConflictException('Script annotation is already in progress.');
            }

            $this->promote->handle(DB::connection(), [$script->episode]);
            $this->replace->handle($script, $data->segments);

            $script->status = 'annotated';
            $script->voice_id = $data->voiceId ?? $script->voice_id;
            $script->error_message = null;
            $script->save();

            $script->episode->title = ContentAudioScriptInput::title(
                $data->title,
                $script->episode->title,
            );
            $script->episode->status = 'draft';
            $script->episode->save();

            return $script->id;
        });

        return ContentAudioScript::query()
            ->whereKey($scriptId)
            ->with($this->show->relations())
            ->firstOrFail();
    }
}
