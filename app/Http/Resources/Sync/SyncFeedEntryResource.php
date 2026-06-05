<?php

namespace App\Http\Resources\Sync;

use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SyncFeedEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'checkpoint' => $this->checkpoint,
            'domain' => $this->domain,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'operation' => $this->operationValue(),
            'server_recorded_at' => $this->server_recorded_at?->toJSON(),
            'payload' => $this->payload,
        ];
    }

    private function operationValue(): ?string
    {
        $operation = $this->resource->getAttributes()['operation'] ?? null;

        if ($operation instanceof SyncFeedOperation) {
            return $operation->value;
        }

        return $operation === null ? null : (string) $operation;
    }
}
