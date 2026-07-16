<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;

class StudyBrowserCardSummaryResource extends StudyCardSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['id'] = $this->resource->clientId();
        $convoLabNoteId = $this->resource->getAttribute('convolab_note_id');

        if (is_string($convoLabNoteId) && $convoLabNoteId !== '') {
            $data['noteId'] = $convoLabNoteId;
            $data['state']['source']['noteId'] = $convoLabNoteId;
        }

        return $data;
    }
}
