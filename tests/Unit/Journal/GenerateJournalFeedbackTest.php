<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\GenerateJournalFeedback;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GenerateJournalFeedbackTest extends TestCase
{
    public function test_uses_stateless_structured_output_and_validates_the_response(): void
    {
        config(['services.openai.api_key' => 'test-key', 'services.openai.base_url' => 'https://openai.test/v1']);
        $output = JournalFeedbackFixture::result();
        Http::fake(['openai.test/*' => Http::response($this->response(json_encode($output)))]);
        $this->assertSame($output, app(GenerateJournalFeedback::class)->handle('映画を見ますた。'));
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://openai.test/v1/responses'
                && $request['store'] === false
                && $request['text']['format']['strict'] === true
                && $request['text']['format']['schema']['additionalProperties'] === false
                && json_decode($request['input'][1]['content'], true) === ['body' => '映画を見ますた。'];
        });
    }

    #[DataProvider('badResponses')]
    public function test_rejects_provider_errors_refusals_and_truncated_or_malformed_output(array $response, int $status): void
    {
        config(['services.openai.api_key' => 'test-key']);
        Http::fake(['*' => Http::response($response, $status)]);
        $this->expectException(RuntimeException::class);
        app(GenerateJournalFeedback::class)->handle('映画を見ますた。');
    }

    public static function badResponses(): array
    {
        return [
            [['status' => 'incomplete'], 200], [['status' => 'failed'], 200], [[], 429],
            [['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal']]]]], 200],
            [self::response('bad JSON'), 200], [self::response('null'), 200], [self::response('{}'), 200],
        ];
    }

    private static function response(string $text): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]]];
    }
}
