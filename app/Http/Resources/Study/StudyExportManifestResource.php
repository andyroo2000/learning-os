<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudyExportManifestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'exported_at' => $this->resource['exported_at'],
            'sections' => $this->resource['sections'],
        ];
    }
}
