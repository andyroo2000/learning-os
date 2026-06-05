<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

class StudyExportManifestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'exported_at' => $this->resource['exported_at'],
            'current_checkpoint' => $this->resource['current_checkpoint'],
            'sections' => $this->sectionsWithPaths(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sectionsWithPaths(): array
    {
        $sections = [];

        foreach ($this->resource['sections'] as $section => $payload) {
            $sections[$section] = [
                ...$payload,
                'path' => route($this->routeNameForSection($section), absolute: false),
            ];
        }

        return $sections;
    }

    private function routeNameForSection(string $section): string
    {
        // Keep this map aligned with GetStudyExportManifestAction whenever export sections change.
        return match ($section) {
            'settings' => 'api.study.export.settings',
            'courses' => 'api.study.export.courses',
            'decks' => 'api.study.export.decks',
            'cards' => 'api.study.export.cards',
            'review_events' => 'api.study.export.review-events',
            'media_assets' => 'api.study.export.media-assets',
            default => throw new LogicException("Study export section [{$section}] is missing a route name."),
        };
    }
}
