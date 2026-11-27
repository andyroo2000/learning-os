<?php

namespace Tests\Feature\Learn;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\FeatureFlags\Actions\UpdateFeatureFlagsAction;
use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_learn_is_off_by_default_and_blocks_every_endpoint(): void
    {
        $this->signIn();
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        $this->getJson('/api/feature-flags')->assertOk()->assertJsonPath('learnEnabled', false);
        $this->assertBlocked((string) Str::ulid());
        $this->assertDatabaseCount('learn_sessions', 0);
    }

    public function test_switch_controls_existing_sessions_without_losing_progress(): void
    {
        $this->signIn();
        $flags = app(UpdateFeatureFlagsAction::class);
        $flags->handle(['learnEnabled' => true]);
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $flags->handle(['learnEnabled' => false]);
        $this->assertBlocked($session['id']);
        $this->assertSame(1, LearnSession::findOrFail($session['id'])->version);
        $flags->handle(['learnEnabled' => true]);
        $this->getJson('/api/learn/sessions/'.$session['id'])->assertOk()->assertJsonPath('data.version', 1);
    }

    public function test_admin_override_is_live_and_does_not_enable_other_users(): void
    {
        $this->asConvoLabAdminBrowser();
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $this->getJson('/api/feature-flags')->assertOk()->assertJsonPath('learnEnabled', false);
        $this->getJson('/api/learn/lessons')->assertOk();
        AdminUserProjection::query()->update(['role' => 'user']);
        $this->assertBlocked($session['id']);
    }

    public function test_only_admins_can_change_the_learn_switch(): void
    {
        $this->asConvoLabAdminBrowser();
        $this->patchJson('/api/feature-flags', ['learnEnabled' => true])->assertOk()->assertJsonPath('learnEnabled', true);
        $this->patchJson('/api/feature-flags', ['learnEnabled' => null])->assertUnprocessable();
        $this->patchJson('/api/feature-flags', ['learnEnabled' => false])->assertOk()->assertJsonPath('learnEnabled', false);
        AdminUserProjection::query()->update(['role' => 'user']);
        $this->patchJson('/api/feature-flags', ['learnEnabled' => true])->assertForbidden();
    }

    private function assertBlocked(string $id): void
    {
        $this->getJson('/api/learn/lessons')->assertNotFound();
        $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertNotFound();
        $this->getJson('/api/learn/sessions/'.$id)->assertNotFound();
        $this->postJson('/api/learn/sessions/'.$id.'/turn', [
            'action' => 'next', 'requestId' => (string) Str::uuid(), 'expectedVersion' => 1,
        ])->assertNotFound();
    }
}
