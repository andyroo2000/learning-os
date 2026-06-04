<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StudySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_study_settings_table_has_minimal_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('study_settings', [
            'id',
            'user_id',
            'new_cards_per_day',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_new_cards_per_day_casts_to_integer(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => '12',
        ]);

        $this->assertSame(12, $settings->refresh()->new_cards_per_day);
    }

    public function test_user_id_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $settings = new StudySettings([
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);

        $this->assertNull($settings->user_id);
        $this->assertSame(12, $settings->new_cards_per_day);
    }

    public function test_owner_cannot_be_changed(): void
    {
        $settings = StudySettings::factory()->create();
        $otherUser = User::factory()->create();

        $settings->user_id = $otherUser->id;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Study settings owner cannot be changed.');

        $settings->save();
    }
}
