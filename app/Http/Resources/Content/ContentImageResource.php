<?php

namespace App\Http\Resources\Content;

use App\Domain\Content\Support\ContentImagePayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ContentImagePayload::fromModel($this->resource);
    }
}
