<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnProgress;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;

class LearnReviewTest extends LearnFeatureTestCase
{
    private function turn(array $session, string $action): array
    {
        return $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => $action, 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ])->assertOk()->json('data');
    }

    public function test_review_keeps_completed_work_and_returns_to_completion_without_the_tutor(): void
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $turns = [['id' => 'saved', 'stepKey' => 'exercise:5', 'text' => 'らしくない',
            'kind' => 'answer', 'message' => 'Correct.', 'segments' => []]];
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'complete', 'index' => 0, 'answered' => false, 'turns' => $turns,
        ]])->save();
        $session = $this->turn($session, 'review');
        $this->assertSame('meaning', $session['prompt']['id']);
        $this->assertSame('review:1:0', LearnProgress::stepKey($session['state']));
        foreach (app(LearnCatalog::class)->find('quartet-1-rashii')['checks'] as $step) {
            $this->assertSame($step['id'], $session['prompt']['id']);
            $preview = $session['nextStep'];
            $session = $this->turn($session, 'next');
            $this->assertSame($preview['phase'], $session['state']['phase']);
            $this->assertSame($preview['prompt'], $session['prompt']);
            $this->assertSame($turns, $session['state']['turns']);
        }
        $this->assertSame('complete', $session['state']['phase']);
        $this->assertFalse($session['state']['reviewing']);
        $session = $this->turn($session, 'review');
        $this->assertSame('review:2:0', LearnProgress::stepKey($session['state']));
        $reply = LearnProgress::reply($session['state'], [
            'action' => 'message', 'requestId' => 'question', 'text' => 'Why?',
        ], ['kind' => 'clarify', 'message' => 'An explanation.', 'segments' => []]);
        $this->assertSame('review:2:0', $reply['turns'][1]['stepKey']);
        $this->assertSame($turns[0], $reply['turns'][0]);
    }

    public function test_review_cannot_skip_unfinished_work(): void
    {
        $this->signIn();
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => 'review', 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ])->assertUnprocessable();
        $saved = LearnSession::findOrFail($session['id']);
        $this->assertSame($session['state'], $saved->state);
        $this->assertNull($saved->pending_request_id);
    }
}
