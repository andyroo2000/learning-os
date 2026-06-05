<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Models\StudySettings;
use App\Domain\Study\Sync\StudySettingsSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class StudySettingsSyncPayloadTest extends TestCase
{
    public function test_settings_payload_uses_client_facing_singleton_keys(): void
    {
        $settings = new StudySettings;
        $settings->setRawAttributes([
            'id' => 123,
            'user_id' => 456,
            'new_cards_per_day' => 12,
            'created_at' => Carbon::parse('2026-06-05T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-06-05T09:15:00Z'),
        ], sync: true);

        $payload = StudySettingsSyncPayload::fromSettings($settings);

        $this->assertSame('study', StudySettingsSyncPayload::DOMAIN);
        $this->assertSame('settings', StudySettingsSyncPayload::RESOURCE_TYPE);
        $this->assertSame('settings', StudySettingsSyncPayload::RESOURCE_ID);
        $this->assertSame([
            'id' => 'settings',
            'new_cards_per_day' => 12,
            'created_at' => '2026-06-05T09:14:00.000000Z',
            'updated_at' => '2026-06-05T09:15:00.000000Z',
        ], $payload);
        $this->assertArrayNotHasKey('user_id', $payload);
    }
}
