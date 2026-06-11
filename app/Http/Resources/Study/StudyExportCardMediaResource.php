<?php

namespace App\Http\Resources\Study;

use App\Support\DateTime\ServerTimestamp;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use UnexpectedValueException;

class StudyExportCardMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'card_id' => $this->resource->card_id,
            'media_asset_id' => $this->resource->media_asset_id,
            'created_at' => $this->timestamp('created_at'),
            'updated_at' => $this->timestamp('updated_at'),
        ];
    }

    private function timestamp(string $attribute): ?string
    {
        $value = $this->resource->{$attribute} ?? null;

        if ($value instanceof CarbonInterface) {
            return $value->toJSON();
        }

        return ServerTimestamp::toJson((string) $value)
            ?? throw new UnexpectedValueException("Study export card media {$attribute} is not a valid timestamp.");
    }
}
