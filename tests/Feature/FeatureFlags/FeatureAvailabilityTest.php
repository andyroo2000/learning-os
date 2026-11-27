<?php

namespace Tests\Feature\FeatureFlags;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Models\FeatureFlag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeatureAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/convolab/feature-availability')->assertUnauthorized();
    }

    public function test_native_users_receive_effective_sections_and_admins_keep_access(): void
    {
        $user = User::factory()->create();
        $this->convoLabProjectionFor($user, (string) Str::uuid());
        $this->signIn($user);
        $this->getJson('/api/convolab/feature-availability')->assertOk()->assertExactJson([
            'flashcardsEnabled' => false, 'readingsEnabled' => false, 'studyTimeEnabled' => false, 'learnEnabled' => false,
        ]);
        FeatureFlag::query()->update(['readingsEnabled' => true]);
        $this->getJson('/api/convolab/feature-availability')->assertOk()->assertJsonPath('readingsEnabled', true);
        AdminUserProjection::where('user_id', $user->id)->update(['role' => 'admin']);
        $this->getJson('/api/convolab/feature-availability')->assertOk()->assertExactJson([
            'flashcardsEnabled' => true, 'readingsEnabled' => true, 'studyTimeEnabled' => true, 'learnEnabled' => true,
        ]);
    }
}
