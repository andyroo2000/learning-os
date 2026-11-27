<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnTutorContract;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class LearnMessageTest extends LearnFeatureTestCase
{
    private function openLesson(): array
    {
        $this->signIn();
        $this->withoutMiddleware(ThrottleRequests::class);
        config()->set('services.openai.api_key', 'local-test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');

        return $this->postJson('/api/learn/lessons/quartet-1-rashii/session')->assertOk()->json('data');
    }

    private function turn(array $session, string $action, ?string $text = null): TestResponse
    {
        return $this->postJson('/api/learn/sessions/'.$session['id'].'/turn', [
            'action' => $action, 'text' => $text, 'expectedVersion' => $session['version'], 'requestId' => (string) Str::uuid(),
        ]);
    }

    private function feedback(string $kind, string $reading = ''): array
    {
        return ['kind' => $kind, 'message' => 'Does that make sense?', 'segments' => $reading === '' ? [] : [[
            'reading' => $reading, 'kind' => 'plain', 'suggestionReading' => null,
            'explanationReading' => null, 'replacementReading' => null,
        ]]];
    }

    private function provider(array $feedback): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => json_encode($feedback, JSON_THROW_ON_ERROR)],
        ]]]])]);
    }

    public function test_inferred_questions_do_not_unlock_progress_but_tentative_answers_do(): void
    {
        $session = $this->openLesson();
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'exercise', 'index' => 0, 'answered' => false, 'turns' => [],
        ]])->save();
        $this->provider($this->feedback('clarify'));
        $session = $this->turn($session, 'message', 'What does indirect mean?')->assertOk()
            ->assertJsonPath('data.state.answered', false)->assertJsonPath('data.state.turns.0.kind', 'clarify')
            ->assertJsonPath('data.state.turns.0.segments', [])->json('data');
        $this->turn($session, 'next')->assertUnprocessable();
        $answer = "  They heard it from a friend?\n";
        $this->provider($this->feedback('answer', $answer));
        $session = $this->turn($session, 'message', $answer)->assertOk()
            ->assertJsonPath('data.state.answered', true)->assertJsonPath('data.state.index', 0)
            ->assertJsonPath('data.state.turns.1.kind', 'answer')->assertJsonPath('data.state.turns.1.text', $answer)->json('data');
        $this->provider($this->feedback('clarify'));
        $session = $this->turn($session, 'message', 'Can you explain a little more?')->assertOk()
            ->assertJsonPath('data.state.answered', true)->assertJsonPath('data.state.index', 0)->json('data');
        $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.index', 1);
    }

    public function test_mixed_answers_and_questions_keep_exact_source_annotations(): void
    {
        $text = '来るんだらしい。 Why is んだ wrong?';
        $output = $this->feedback('answer');
        $output['segments'] = [
            ['reading' => '来[く]るんだらしい。', 'kind' => 'correction', 'suggestionReading' => '来[く]るらしい。',
                'explanationReading' => 'Connect the plain form directly.', 'replacementReading' => '来[く]るらしい。'],
            ['reading' => ' Why is んだ wrong?', 'kind' => 'plain', 'suggestionReading' => null,
                'explanationReading' => null, 'replacementReading' => null],
        ];
        $result = LearnTutorContract::validate($text, 'message', $output);
        $this->assertSame('answer', $result['kind']);
        $this->assertSame($text, implode('', array_column($result['segments'], 'text')));
        $this->assertSame('plain', $result['segments'][1]['kind']);
        $this->assertArrayNotHasKey('correctedText', $result);
    }

    public function test_messages_outside_questions_cannot_become_answers_even_if_provider_misclassifies(): void
    {
        $session = $this->openLesson();
        foreach (['ready', 'complete'] as $phase) {
            LearnSession::findOrFail($session['id'])->forceFill(['state' => [
                'phase' => $phase, 'index' => 0, 'answered' => false, 'turns' => [],
            ]])->save();
            $this->provider($this->feedback('answer', 'Thanks!'));
            $session = $this->turn($session, 'message', 'Thanks!')->assertOk()
                ->assertJsonPath('data.state.phase', $phase)->assertJsonPath('data.state.answered', false)
                ->assertJsonPath('data.state.turns.0.kind', 'clarify')->assertJsonPath('data.state.turns.0.segments', [])->json('data');
        }
    }

    public function test_introduction_acknowledgements_allow_explicit_continuation_without_practice(): void
    {
        foreach (app(LearnCatalog::class)->all() as $lesson) {
            foreach ($lesson['checks'] as $step) {
                $this->assertSame('Does that make sense so far?', $step['prompt']);
            }
        }
        $session = $this->openLesson();
        $this->provider($this->feedback('clarify'));
        $session = $this->turn($session, 'message', 'Not quite. What does indirect mean?')->assertOk()
            ->assertJsonPath('data.state.answered', false)->json('data');
        $this->assertSame(0, $session['state']['index']);
        foreach (range(0, count(app(LearnCatalog::class)->find('quartet-1-rashii')['checks']) - 1) as $index) {
            $this->provider($this->feedback('acknowledge'));
            $session = $this->turn($session, 'message', 'Yes, that makes sense.')->assertOk()
                ->assertJsonPath('data.state.index', $index)->assertJsonPath('data.state.answered', true)->json('data');
            $turn = $session['state']['turns'][array_key_last($session['state']['turns'])];
            $this->assertSame('acknowledge', $turn['kind']);
            $this->assertSame([], $turn['segments']);
            $session = $this->turn($session, 'next')->assertOk()->json('data');
        }
        $this->assertSame('ready', $session['state']['phase']);
        $this->turn($session, 'next')->assertOk()->assertJsonPath('data.state.phase', 'exercise')
            ->assertJsonPath('data.prompt.id', 'rumor')->assertJsonPath('data.state.answered', false);
    }

    public function test_acknowledging_a_workbook_prompt_never_counts_as_attempting_it(): void
    {
        $session = $this->openLesson();
        LearnSession::findOrFail($session['id'])->forceFill(['state' => [
            'phase' => 'exercise', 'index' => 0, 'answered' => false, 'turns' => [],
        ]])->save();
        $this->provider($this->feedback('acknowledge'));
        $session = $this->turn($session, 'message', 'Yes, that makes sense.')->assertOk()
            ->assertJsonPath('data.state.answered', false)->assertJsonPath('data.state.turns.0.kind', 'clarify')->json('data');
        $this->turn($session, 'next')->assertUnprocessable();
    }

    public function test_missing_intent_is_rejected_without_saving_or_unlocking_the_lesson(): void
    {
        $session = $this->openLesson();
        $feedback = $this->feedback('answer', 'Apparently.');
        unset($feedback['kind']);
        $this->provider($feedback);
        $this->turn($session, 'message', 'Apparently.')->assertStatus(503);
        Http::assertSentCount(2);
        $saved = LearnSession::findOrFail($session['id']);
        $this->assertFalse($saved->state['answered']);
        $this->assertSame([], $saved->state['turns']);
        $this->assertNull($saved->pending_request_id);
        $this->turn($session, 'message', '   ')->assertUnprocessable();
    }
}
