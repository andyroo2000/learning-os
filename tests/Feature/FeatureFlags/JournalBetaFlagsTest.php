<?php

namespace Tests\Feature\FeatureFlags;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalBetaFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_section_switches_support_partial_updates(): void
    {
        $this->asConvoLabAdminBrowser()
            ->patchJson('/api/feature-flags', ['readingsEnabled' => true, 'studyTimeEnabled' => true])
            ->assertOk()->assertJsonPath('readingsEnabled', true)->assertJsonPath('studyTimeEnabled', true)
            ->assertJsonPath('flashcardsEnabled', false);
        $this->patchJson('/api/feature-flags', ['readingsEnabled' => false])->assertOk()
            ->assertJsonPath('readingsEnabled', false)->assertJsonPath('studyTimeEnabled', true);
    }

    public function test_new_section_switches_reject_malformed_values(): void
    {
        $this->asConvoLabAdminBrowser()
            ->patchJson('/api/feature-flags', ['readingsEnabled' => [], 'studyTimeEnabled' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['readingsEnabled', 'studyTimeEnabled']);
    }
}
