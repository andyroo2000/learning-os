<?php

namespace Tests\Feature\Learn;

use App\Domain\Journal\Actions\SaveJournalTutor;
use App\Domain\Learn\Services\GenerateLearnFeedback;
use Illuminate\Support\Str;

class LearnTutorPreferenceTest extends LearnFeatureTestCase
{
    public function test_learn_uses_the_current_journal_tutor_for_each_reply(): void
    {
        $user = $this->signIn();
        $session = $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
        $feedback = ['kind' => 'clarify', 'message' => 'Here is why.', 'segments' => []];
        $this->mock(GenerateLearnFeedback::class)->shouldReceive('handle')->once()
            ->withArgs(fn ($lesson, $state, $input, $tutor) => $tutor === 'ren')->andReturn($feedback);
        app(SaveJournalTutor::class)->handle($user->id, ['tutorId' => 'ren']);
        $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => 'message', 'text' => 'Why?', 'expectedVersion' => 1, 'requestId' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.state.turns.0.message', 'Here is why.');
    }
}
