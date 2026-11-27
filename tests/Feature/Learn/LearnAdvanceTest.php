<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LearnAdvanceTest extends LearnFeatureTestCase
{
    public function test_understanding_button_uses_preloaded_steps_without_calling_the_tutor(): void
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        Http::fake();
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $url = '/api/learn/sessions/'.$session['id'].'/turn';
        foreach (range(0, count(app(LearnCatalog::class)->find('quartet-1-rashii')['checks'])) as $step) {
            $preview = $session['nextStep'];
            $this->assertNotNull($preview);
            $input = ['action' => 'next', 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid()];
            $session = $this->postJson($url, $input)->assertOk()->json('data');
            $this->assertSame($preview['phase'], $session['state']['phase']);
            $this->assertSame($preview['index'], $session['state']['index']);
            $this->assertSame($preview['prompt'], $session['prompt']);
            $this->assertSame([], $session['state']['turns']);
            $this->postJson($url, $input)->assertOk()->assertJsonPath('data.version', $session['version']);
        }
        $this->assertSame('exercise', $session['state']['phase']);
        $this->assertSame('rumor', $session['prompt']['id']);
        $this->assertNull($session['nextStep']);
        $this->postJson($url, ['action' => 'next', 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid()])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_stale_understanding_click_cannot_skip_another_step(): void
    {
        $this->signIn();
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        $session = $this->postJson('/api/learn/lessons/quartet-1-toori/session')->assertOk()->json('data');
        $url = '/api/learn/sessions/'.$session['id'].'/turn';
        $input = ['action' => 'next', 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid()];
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.state.index', 1);
        $this->postJson($url, [...$input, 'requestId' => (string) Str::uuid()])->assertConflict();
        $this->getJson('/api/learn/sessions/'.$session['id'])->assertOk()->assertJsonPath('data.state.index', 1);
    }
}
