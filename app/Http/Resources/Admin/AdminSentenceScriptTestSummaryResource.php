<?php

namespace App\Http\Resources\Admin;

use App\Support\DateTime\ConvoLabTimestamp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AdminSentenceScriptTestSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sentence' => $this->sentence,
            'translation' => $this->translation,
            'estimatedDurationSecs' => $this->estimated_duration_secs,
            'parseError' => $this->parse_error,
            'createdAt' => ConvoLabTimestamp::serialize($this->created_at),
        ];
    }
}
