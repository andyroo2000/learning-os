<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPronunciationDictionaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'keepKanji' => $this->keep_kanji,
            'forceKana' => (object) $this->force_kana,
            'verbKana' => (object) $this->verb_kana,
            'updatedAt' => $this->when(
                $this->updated_at !== null,
                fn (): string => ConvoLabTimestamp::serialize($this->updated_at),
            ),
        ];
    }
}
