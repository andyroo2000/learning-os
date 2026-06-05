<?php

namespace App\Http\Resources\Study;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class StudyOverviewResource extends JsonResource
{
    /**
     * Public API fields are opt-in so internal counters can guide session logic without
     * becoming client-visible contracts.
     */
    private const PUBLIC_KEYS = [
        'due_count',
        'failed_count',
        'new_count',
        'new_cards_per_day',
        'new_cards_introduced_today',
        'new_cards_available_today',
        'learning_count',
        'review_count',
        'suspended_count',
        'total_cards',
        'latest_import',
        'next_due_at',
    ];

    /**
     * @param  array<string, mixed>  $overview
     * @return array<string, mixed>
     */
    public static function publicData(array $overview): array
    {
        $data = Arr::only($overview, self::PUBLIC_KEYS);

        $data['latest_import'] = array_key_exists('latest_import', $overview)
            ? self::latestImportData($overview['latest_import'])
            : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::publicData($this->resource);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function latestImportData(mixed $latestImport): ?array
    {
        if ($latestImport === null) {
            return null;
        }

        return StudyImportJobResource::make($latestImport)->resolve();
    }
}
