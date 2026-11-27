<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class LearnNavigationTest extends LearnFeatureTestCase
{
    private function open(): array
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');

        return $this->postJson('/api/learn/lessons/quartet-1-toori/session')->assertOk()->json('data');
    }

    private function turn(array $session, string $action): TestResponse
    {
        return $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => $action, 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ]);
    }

    public function test_stage_navigation_remembers_each_place_and_never_completes_unfinished_work(): void
    {
        $session = $this->open();
        $session = $this->turn($session, 'next')->assertOk()->json('data');
        $session = $this->turn($session, 'workbook')->assertOk()
            ->assertJsonPath('data.prompt.id', 'fuji')->assertJsonPath('data.state.answered', false)->json('data');
        $this->turn($session, 'reflect')->assertUnprocessable();
        $this->turn($session, 'next')->assertUnprocessable();
        $session = $this->turn($session, 'understand')->assertOk()
            ->assertJsonPath('data.state.index', 1)->assertJsonPath('data.prompt.id', 'matching')->json('data');
        $session = $this->turn($session, 'ready')->assertOk()->assertJsonPath('data.state.phase', 'ready')->json('data');
        $session = $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.phase', 'exercise')->json('data');
        $this->assertFalse($session['state']['completed']);
        $this->assertSame([], $session['state']['turns']);
        $this->turn($session, 'understand')->assertOk()->assertJsonPath('data.state.index', 1);
        $this->turn($session, 'workbook')->assertConflict();
    }

    public function test_workbook_answer_and_feedback_survive_round_trip_and_readiness(): void
    {
        $session = $this->open();
        $turns = [['id' => 'answer', 'stepKey' => 'exercise:2', 'kind' => 'answer',
            'text' => '教えたとおりにしました。', 'message' => 'Feedback.', 'segments' => []]];
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'exercise', 'index' => 2, 'answered' => true, 'turns' => $turns,
        ]])->save();
        $session = $this->turn($session, 'understand')->assertOk()->json('data');
        $session = $this->turn($session, 'workbook')->assertOk()
            ->assertJsonPath('data.state.index', 2)->assertJsonPath('data.state.answered', true)->json('data');
        $this->assertSame($turns, $session['state']['turns']);
        $session = $this->turn($session, 'ready')->assertOk()->json('data');
        $session = $this->turn($session, 'next')->assertOk()
            ->assertJsonPath('data.state.index', 2)->assertJsonPath('data.nextStep.index', 3)->json('data');
        $this->turn($session, 'next')->assertOk()
            ->assertJsonPath('data.state.index', 3)->assertJsonPath('data.state.answered', false);
    }

    public function test_legacy_completed_sessions_can_revisit_answers_without_losing_completion(): void
    {
        $session = $this->open();
        $turns = [];
        foreach (range(0, 5) as $index) {
            $turns[] = ['id' => 'answer-'.$index, 'stepKey' => 'exercise:'.$index,
                'kind' => 'answer', 'text' => 'Saved answer', 'message' => 'Feedback.', 'segments' => []];
        }
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'complete', 'index' => 0, 'answered' => false, 'turns' => $turns,
        ]])->save();
        $session = $this->turn($session, 'workbook')->assertOk()
            ->assertJsonPath('data.state.index', 0)->assertJsonPath('data.state.completed', true)
            ->assertJsonPath('data.state.answered', true)->assertJsonPath('data.nextStep.answered', true)->json('data');
        $session = $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.index', 1)->json('data');
        $session = $this->turn($session, 'reflect')->assertOk()->json('data');
        $session = $this->turn($session, 'review')->assertOk()->json('data');
        $reviewRun = $session['state']['reviewRun'];
        $session = $this->turn($session, 'workbook')->assertOk()
            ->assertJsonPath('data.state.index', 1)->assertJsonPath('data.state.reviewing', false)->json('data');
        $session = $this->turn($session, 'understand')->assertOk()
            ->assertJsonPath('data.state.reviewing', true)->assertJsonPath('data.state.reviewRun', $reviewRun)->json('data');
        $this->assertSame($turns, $session['state']['turns']);
        $this->turn($session, 'restart')->assertOk()->assertJsonPath('data.state.turns', [])
            ->assertJsonMissingPath('data.state.bookmarks')->assertJsonMissingPath('data.state.completed');
    }
}
