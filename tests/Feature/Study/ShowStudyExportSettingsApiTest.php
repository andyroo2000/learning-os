<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowStudyExportSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/study/export/settings')->assertUnauthorized();
    }

    public function test_show_returns_existing_settings_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        StudySettings::factory()->for($otherUser)->create([
            'new_cards_per_day' => 3,
        ]);

        $settings = StudySettings::factory()->for($user)->create([
            'new_cards_per_day' => 14,
        ]);

        $this->getJson('/api/study/export/settings')
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', 14)
            ->assertJsonPath('data.created_at', $settings->created_at?->toJSON())
            ->assertJsonPath('data.updated_at', $settings->updated_at?->toJSON());
    }

    public function test_show_materializes_default_settings_when_missing(): void
    {
        $user = $this->signIn();

        $this->getJson('/api/study/export/settings')
            ->assertOk()
            ->assertJsonPath('data.new_cards_per_day', StudySettings::DEFAULT_NEW_CARDS_PER_DAY);

        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => StudySettings::DEFAULT_NEW_CARDS_PER_DAY,
        ]);
    }
}
