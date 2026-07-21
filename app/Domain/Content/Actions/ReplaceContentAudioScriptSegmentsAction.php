<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentAudioScript;
use App\Domain\Content\Models\ContentAudioScriptMedia;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use Illuminate\Support\Str;

final class ReplaceContentAudioScriptSegmentsAction
{
    /**
     * @param  list<array{text: string, reading: string|null, translation: string, imagePrompt: string|null}>  $segments
     * @return list<string|null> Storage paths whose database rows were removed transactionally.
     */
    public function handle(ContentAudioScript $script, array $segments): array
    {
        $replacedMediaIds = $script->segments()
            ->whereNotNull('image_media_id')
            ->pluck('image_media_id')
            ->unique()
            ->values()
            ->all();

        $script->segments()->delete();
        $script->renders()->delete();

        $orphanedMedia = $replacedMediaIds === []
            ? collect()
            : ContentAudioScriptMedia::query()
                ->whereIn('id', $replacedMediaIds)
                ->where('source_kind', 'generated')
                ->where('media_kind', 'image')
                ->whereDoesntHave('segments')
                ->lockForUpdate()
                ->get(['id', 'storage_path']);
        if ($orphanedMedia->isNotEmpty()) {
            ContentAudioScriptMedia::query()
                ->whereKey($orphanedMedia->pluck('id'))
                ->delete();
        }

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
        $script->save();

        return $orphanedMedia->pluck('storage_path')->all();
    }
}
