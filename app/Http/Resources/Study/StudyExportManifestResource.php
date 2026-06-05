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
            'sections' => [
                'courses' => [
                    ...$this->resource['sections']['courses'],
                    'path' => route('api.study.export.courses', absolute: false),
                ],
                'decks' => [
                    ...$this->resource['sections']['decks'],
                    'path' => route('api.study.export.decks', absolute: false),
                ],
                'cards' => [
                    ...$this->resource['sections']['cards'],
                    'path' => route('api.study.export.cards', absolute: false),
                ],
                'review_events' => [
                    ...$this->resource['sections']['review_events'],
                    'path' => route('api.study.export.review-events', absolute: false),
                ],
                'media_assets' => [
                    ...$this->resource['sections']['media_assets'],
                    'path' => route('api.study.export.media-assets', absolute: false),
                ],
            ],
        ];
    }
}
