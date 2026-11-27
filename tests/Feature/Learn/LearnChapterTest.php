<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class LearnChapterTest extends LearnFeatureTestCase
{
    private function turn(array $session, string $action, ?string $text = null): TestResponse
    {
        return $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => $action, 'text' => $text, 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ]);
    }

    private function open(string $id): array
    {
        return $this->postJson('/api/learn/lessons/'.$id.'/session')->assertOk()->json('data');
    }

    public function test_previous_keeps_feedback_review_identity_and_bookmarks_without_tutor_calls(): void
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        $session = $this->open('quartet-1-toori');
        $this->turn($session, 'previous')->assertUnprocessable();
        $turns = [['id' => 'clarification', 'stepKey' => 'review:2:0', 'kind' => 'clarify',
            'text' => 'Why?', 'message' => 'Saved explanation', 'segments' => []]];
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'check', 'index' => 1, 'answered' => false, 'reviewing' => true, 'reviewRun' => 2, 'turns' => $turns,
        ]])->save();
        $prior = $session;
        $session = $this->turn($session, 'previous')->assertOk()
            ->assertJsonPath('data.state.index', 0)->assertJsonPath('data.state.reviewRun', 2)
            ->assertJsonPath('data.prompt.id', 'connection')->json('data');
        $this->assertSame($turns, $session['state']['turns']);
        $this->turn($prior, 'previous')->assertConflict();
        $session = $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.index', 1)->json('data');
        $session = $this->turn($session, 'previous')->assertOk()->json('data');
        $session = $this->turn($session, 'workbook')->assertOk()->json('data');
        $this->turn($session, 'previous')->assertUnprocessable();
        $session = $this->turn($session, 'understand')->assertOk()->assertJsonPath('data.state.index', 0)->json('data');
        $this->assertSame($turns, $session['state']['turns']);
    }

    public function test_explanation_only_points_finish_without_empty_workbook_states(): void
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldNotReceive('handle');
        foreach (['quartet-1-toieba', 'quartet-1-kikkake'] as $id) {
            $session = $this->open($id);
            $this->turn($session, 'workbook')->assertUnprocessable();
            $this->turn($session, 'ready')->assertUnprocessable();
            $this->turn($session, 'reflect')->assertUnprocessable();
            foreach (app(LearnCatalog::class)->find($id)['checks'] as $step) {
                $this->assertSame($step['id'], $session['prompt']['id']);
                $session = $this->turn($session, 'next')->assertOk()->json('data');
            }
            $this->assertSame('complete', $session['state']['phase']);
            $this->assertTrue($session['state']['completed']);
            $session = $this->turn($session, 'review')->assertOk()->assertJsonPath('data.state.index', 0)->json('data');
            $this->turn($session, 'reflect')->assertOk()->assertJsonPath('data.state.phase', 'complete');
        }
    }

    public function test_every_chapter_lesson_runs_through_its_exact_dedicated_workbook_set(): void
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GenerateLearnFeedback::class)->shouldReceive('handle')->times(42)->andReturn([
            'message' => 'Test feedback.', 'segments' => [],
        ]);
        $lessons = app(LearnCatalog::class)->all();
        $this->assertSame(['quartet-1-toieba', 'quartet-1-nara', 'quartet-1-toori', 'quartet-1-rashii',
            'quartet-1-tameni', 'quartet-1-kikkake', 'quartet-1-youninaru', 'quartet-1-younisuru', 'quartet-1-niyoruto'], array_column($lessons, 'id'));
        $this->assertSame([0, 7, 6, 6, 6, 0, 6, 6, 5], array_map(fn ($lesson) => count($lesson['exercises']), $lessons));
        foreach ($lessons as $lesson) {
            $this->completeLesson($lesson);
        }
    }

    private function completeLesson(array $lesson): void
    {
        $session = $this->open($lesson['id']);
        foreach ($lesson['checks'] as $step) {
            $this->assertSame($step['id'], $session['prompt']['id']);
            $this->assertSame('Does that make sense so far?', $step['prompt']);
            $session = $this->turn($session, 'next')->assertOk()->json('data');
        }
        if ($lesson['exercises']) {
            $this->assertSame('ready', $session['state']['phase']);
            $session = $this->turn($session, 'next')->assertOk()->json('data');
        }
        foreach ($lesson['exercises'] as $exercise) {
            $this->assertSame($exercise, $session['prompt']);
            $this->turn($session, 'next')->assertUnprocessable();
            $session = $this->turn($session, 'answer', 'An attempted completion.')->assertOk()->json('data');
            $session = $this->turn($session, 'next')->assertOk()->json('data');
        }
        $this->assertSame('complete', $session['state']['phase']);
        $this->assertCount(count($lesson['exercises']), $session['state']['turns']);
    }
}
