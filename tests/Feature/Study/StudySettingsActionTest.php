<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\GetStudySettingsAction;
use App\Domain\Study\Actions\UpdateStudySettingsAction;
use App\Domain\Study\Models\StudySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class StudySettingsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_creates_default_settings_once(): void
    {
        $user = User::factory()->create();

        $first = app(GetStudySettingsAction::class)->handle($user->id);
        $second = app(GetStudySettingsAction::class)->handle($user->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(StudySettings::DEFAULT_NEW_CARDS_PER_DAY, $first->new_cards_per_day);
        $this->assertDatabaseCount('study_settings', 1);
    }

    public function test_update_creates_settings_when_missing(): void
    {
        $user = User::factory()->create();

        $settings = app(UpdateStudySettingsAction::class)->handle($user->id, 12);

        $this->assertSame(12, $settings->new_cards_per_day);
        $this->assertDatabaseHas('study_settings', [
            'user_id' => $user->id,
            'new_cards_per_day' => 12,
        ]);
    }

    public function test_update_changes_existing_settings(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);

        $updated = app(UpdateStudySettingsAction::class)->handle($settings->user_id, 0);

        $this->assertSame(0, $updated->new_cards_per_day);
        $this->assertDatabaseCount('study_settings', 1);
    }

    public function test_update_only_changes_the_requested_users_settings(): void
    {
        $settings = StudySettings::factory()->create([
            'new_cards_per_day' => 20,
        ]);
        $otherSettings = StudySettings::factory()->create([
            'new_cards_per_day' => 42,
        ]);

        app(UpdateStudySettingsAction::class)->handle($settings->user_id, 12);

        $this->assertSame(12, $settings->refresh()->new_cards_per_day);
        $this->assertSame(42, $otherSettings->refresh()->new_cards_per_day);
    }

    public function test_update_rejects_values_outside_the_supported_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('new_cards_per_day must be an integer between 0 and 1000.');

        app(UpdateStudySettingsAction::class)->handle(User::factory()->create()->id, 1001);
    }
}
