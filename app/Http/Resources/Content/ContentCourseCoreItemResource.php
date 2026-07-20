<?php

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentCourseCoreItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'courseId' => $this->course_id,
            'textL2' => $this->text_l2,
            'readingL2' => $this->reading_l2,
            'translationL1' => $this->translation_l1,
            'complexityScore' => $this->complexity_score,
            'sourceEpisodeId' => $this->source_episode_id,
            'sourceSentenceId' => $this->source_sentence_id,
            'sourceUnitIndex' => $this->source_unit_index,
            'components' => $this->components,
        ];
    }
}
