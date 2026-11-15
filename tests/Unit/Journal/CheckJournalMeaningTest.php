<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\CheckJournalMeaning;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CheckJournalMeaningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.api_key' => 'test-key', 'services.openai.base_url' => 'https://openai.test/v1', 'journal.model' => 'journal-test']);
        Http::preventStrayRequests();
    }

    public function test_checks_original_text_and_prior_answers_without_generating_feedback(): void
    {
        $body = '車で運転するながら、モーリさんはスマホで映画を調べた。';
        $answers = [['question' => 'Who was driving?', 'answer' => 'I was driving']];
        $result = ['resolved' => true, 'question' => null, 'confirmedMeaning' => 'The writer was driving while Molly looked up the movie.'];
        Http::fake(['*' => Http::response(self::response($result))]);
        $this->assertSame($result, app(CheckJournalMeaning::class)->handle($body, $answers));
        Http::assertSent(function (Request $request) use ($body, $answers): bool {
            return $request->url() === 'https://openai.test/v1/responses'
                && $request['store'] === false
                && $request['model'] === 'journal-test'
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['additionalProperties'] === false
                && json_decode($request['input'][1]['content'], true) === compact('body', 'answers')
                && str_contains($request['input'][0]['content'], 'Do not force resolution');
        });
    }

    public function test_an_inconclusive_answer_can_produce_a_replacement_question(): void
    {
        $result = ['resolved' => false, 'question' => ['prompt' => 'Were you driving, or was Molly driving?', 'choices' => ['I was driving', 'Molly was driving']], 'confirmedMeaning' => ''];
        Http::fake(['*' => Http::response(self::response($result))]);
        $answers = [['question' => 'Who was driving?', 'answer' => 'yes']];
        $this->assertSame($result, app(CheckJournalMeaning::class)->handle('車を運転しながら、モーリさんは調べた。', $answers));
        Http::assertSent(fn (Request $request): bool => json_decode($request['input'][1]['content'], true)['answers'] === $answers);
    }

    public function test_clear_entries_can_resolve_without_a_question_or_invented_clarification(): void
    {
        $result = ['resolved' => true, 'question' => null, 'confirmedMeaning' => ''];
        Http::fake(['*' => Http::response(self::response($result))]);
        $this->assertSame($result, app(CheckJournalMeaning::class)->handle('映画を見ました。'));
    }

    #[DataProvider('invalidResults')]
    public function test_invalid_or_inconsistent_provider_output_never_resolves(array $response, int $status = 200): void
    {
        Http::fake(['*' => Http::response($response, $status)]);
        $this->expectException(RuntimeException::class);
        app(CheckJournalMeaning::class)->handle('映画を見ました。');
    }

    public static function invalidResults(): array
    {
        $question = ['prompt' => 'Who was driving?', 'choices' => ['Me', 'Molly']];

        return [
            'incomplete' => [['status' => 'incomplete']],
            'rate limited' => [[], 429],
            'refusal' => [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal']]]]]],
            'scalar' => [self::response(null)],
            'missing fields' => [self::response([])],
            'unresolved without question' => [self::response(['resolved' => false, 'question' => null, 'confirmedMeaning' => ''])],
            'resolved with question' => [self::response(['resolved' => true, 'question' => $question, 'confirmedMeaning' => ''])],
            'premature meaning' => [self::response(['resolved' => false, 'question' => $question, 'confirmedMeaning' => 'Molly drove'])],
            'wrong boolean type' => [self::response(['resolved' => '1', 'question' => null, 'confirmedMeaning' => ''])],
            'duplicate choices' => [self::response(['resolved' => false, 'question' => ['prompt' => 'Who?', 'choices' => ['Me', 'Me']], 'confirmedMeaning' => ''])],
            'blank question' => [self::response(['resolved' => false, 'question' => ['prompt' => ' ', 'choices' => ['Me', 'Molly']], 'confirmedMeaning' => ''])],
        ];
    }

    public function test_invalid_direct_input_is_rejected_before_a_provider_call(): void
    {
        try {
            app(CheckJournalMeaning::class)->handle('映画', [['question' => 'Who?', 'answer' => str_repeat('あ', 2001)]]);
            $this->fail('Oversized answer was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.0.answer', $exception->errors());
        }
        Http::assertNothingSent();
    }

    private static function response(mixed $result): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]];
    }
}
