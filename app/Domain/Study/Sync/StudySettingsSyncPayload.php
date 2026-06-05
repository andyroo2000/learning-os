<?php

namespace App\Domain\Study\Sync;

use App\Domain\Study\Models\StudySettings;

final class StudySettingsSyncPayload
{
    public const DOMAIN = 'study';

    public const RESOURCE_TYPE = 'settings';

    public const RESOURCE_ID = 'settings';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function fromSettings(StudySettings $settings): array
    {
        return [
            'id' => self::RESOURCE_ID,
            'new_cards_per_day' => $settings->new_cards_per_day,
            'created_at' => $settings->created_at?->toJSON(),
            'updated_at' => $settings->updated_at?->toJSON(),
        ];
    }
}
