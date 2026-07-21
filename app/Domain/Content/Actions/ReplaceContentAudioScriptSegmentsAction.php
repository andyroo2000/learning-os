<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use Illuminate\Support\Str;

final class ReplaceContentAudioScriptSegmentsAction
{
    /** @param list<array{text: string, reading: string|null, translation: string, imagePrompt: string|null}> $segments */
    public function handle(ContentAudioScript $script, array $segments): void
    {
        $script->segments()->delete();
        $script->renders()->delete();

        foreach ($segments as $index => $segment) {
            $model = new ContentAudioScriptSegment;
            $model->id = (string) Str::uuid();
            $model->script_id = $script->id;
            $model->sort_order = $index;
            $model->text = $segment['text'];
            $model->reading = $segment['reading'];
            $model->translation = $segment['translation'];
            $model->image_prompt = $segment['imagePrompt'];
            $model->image_status = 'pending';
            $model->metadata = [
                'japanese' => [
                    'kanji' => $segment['text'],
                    'kana' => $segment['reading'] ?? $segment['text'],
                    'furigana' => $segment['reading'] ?? $segment['text'],
                ],
            ];
            $model->save();
        }

        $script->image_status = 'pending';
        $script->image_error_message = null;
    }
}
