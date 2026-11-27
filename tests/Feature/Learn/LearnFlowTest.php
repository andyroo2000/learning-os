<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class LearnFlowTest extends LearnFeatureTestCase
{
    private function openLesson(): array
    {
        return $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
    }

    private function turn(array $session, string $action, ?string $text = null): TestResponse
    {
        return $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => $action, 'text' => $text, 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ]);
    }

    private function tutor(): void
    {
        $this->mock(GenerateLearnFeedback::class)->shouldReceive('handle')->andReturnUsing(
            fn ($lesson, $state, $input) => ['message' => 'Does that make sense?', 'segments' => [
                ['text' => $input['text'], 'reading' => $input['text'], 'kind' => 'plain'],
            ]],
        );
    }

    public function test_lessons_require_login_and_sessions_are_private_and_resumable(): void
    {
        $this->getJson('/api/learn/lessons')->assertUnauthorized();
        $this->signIn();
        $session = $this->openLesson();
        $this->assertSame($session['id'], $this->openLesson()['id']);
        $catalog = $this->getJson('/api/learn/lessons')->assertOk()->assertJsonCount(9, 'lessons')->json();
        $this->assertArrayNotHasKey('exercises', $catalog['lessons'][0]);
        $this->assertArrayNotHasKey('introduction', $catalog['lessons'][0]);
        $this->assertArrayNotHasKey('checks', $catalog['lessons'][0]);
        $this->assertSame('Let’s start with “apparently”.', $session['prompt']['teaching']['title']);
        $this->assertArrayNotHasKey('teachingNotes', $catalog['lessons'][0]);
        $this->signIn();
        $this->getJson('/api/learn/sessions/'.$session['id'])->assertNotFound();
        $this->turn($session, 'restart')->assertNotFound();
    }

    public function test_clarification_never_advances_and_workbook_requires_explicit_readiness(): void
    {
        $this->signIn();
        $this->tutor();
        $this->withoutMiddleware(ThrottleRequests::class);
        $session = $this->openLesson();
        $session = $this->turn($session, 'clarify', 'What does indirect mean?')->assertOk()->json('data');
        $this->assertFalse($session['state']['answered']);
        $session = $this->turn($session, 'answer', "  Something I heard.\n")->assertOk()->json('data');
        $this->assertSame("  Something I heard.\n", $session['state']['turns'][1]['text']);
        $this->assertSame(0, $session['state']['index']);
        $session = $this->turn($session, 'next')->assertOk()->json('data');
        $count = count(app(LearnCatalog::class)->find('quartet-1-rashii')['checks']);
        for ($index = 1; $index < $count - 1; $index++) {
            $session = $this->turn($session, 'next')->assertOk()->json('data');
        }
        $session = $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.phase', 'ready')->json('data');
        $this->assertNull($session['prompt']);
        $this->turn($session, 'answer', 'Guess')->assertUnprocessable();
        $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.phase', 'exercise')->assertJsonPath('data.state.index', 0);
    }

    public function test_retries_are_idempotent_and_stale_tabs_cannot_overwrite_progress(): void
    {
        $this->signIn();
        $this->tutor();
        $session = $this->openLesson();
        $input = ['action' => 'answer', 'text' => 'Apparently.', 'expectedVersion' => 1, 'requestId' => (string) Str::uuid()];
        $url = '/api/learn/sessions/'.$session['id'].'/turn';
        $first = $this->postJson($url, $input)->assertOk()->json('data');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(1, 'data.state.turns');
        $this->turn($session, 'next')->assertConflict();
        $this->turn($first, 'next')->assertOk()->assertJsonPath('data.state.index', 1);
    }

    public function test_tutor_failure_preserves_progress_and_releases_the_session_for_retry(): void
    {
        $this->signIn();
        $this->mock(GenerateLearnFeedback::class)->shouldReceive('handle')->once()->andThrow(new \RuntimeException('Provider failed'));
        $session = $this->openLesson();
        $this->turn($session, 'answer', 'Apparently.')->assertStatus(503);
        $saved = LearnSession::findOrFail($session['id']);
        $this->assertNull($saved->pending_request_id);
        $this->assertSame(1, $saved->version);
        $this->assertSame([], $saved->state['turns']);
        $this->tutor();
        $this->turn($session, 'answer', 'Apparently.')->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_last_exercise_completes_only_when_ready_and_restart_clears_saved_work(): void
    {
        $this->signIn();
        $this->tutor();
        $session = $this->openLesson();
        LearnSession::whereKey($session['id'])->update(['state' => json_encode([
            'phase' => 'exercise', 'index' => 5, 'answered' => false, 'turns' => [],
        ], JSON_THROW_ON_ERROR)]);
        $session = $this->turn($session, 'answer', 'らしくない。')->assertOk()->assertJsonPath('data.state.phase', 'exercise')->json('data');
        $this->assertSame('autumn', $session['state']['turns'][0]['prompt']['id']);
        $session = $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.phase', 'complete')->json('data');
        $this->turn($session, 'next')->assertUnprocessable();
        $this->turn($session, 'restart')->assertOk()->assertJsonPath('data.state.phase', 'check')->assertJsonCount(0, 'data.state.turns');
    }

    public function test_an_in_flight_response_blocks_other_writes(): void
    {
        $this->signIn();
        $session = $this->openLesson();
        LearnSession::whereKey($session['id'])->update(['pending_request_id' => (string) Str::uuid(), 'pending_at' => now()]);
        $this->turn($session, 'restart')->assertConflict();
        $this->getJson('/api/learn/sessions/'.$session['id'])->assertOk()->assertJsonPath('data.busy', true);
    }
}
