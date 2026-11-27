<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Services\GenerateLearnFeedback;
use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnProgress;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LearnProviderTest extends TestCase
{
    private function response(string $reading): array
    {
        $feedback = ['message' => 'Ready to continue?', 'segments' => [[
            'reading' => $reading, 'kind' => 'plain', 'suggestionReading' => null,
            'explanationReading' => null, 'replacementReading' => null,
        ]]];

        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => json_encode($feedback, JSON_THROW_ON_ERROR)],
        ]]]];
    }

    public function test_invalid_furigana_is_repaired_once_without_changing_the_original_answer(): void
    {
        config()->set('services.openai.api_key', 'local-test-key');
        config()->set('services.openai.base_url', 'https://api.openai.com/v1');
        Http::fake(['*' => Http::sequence()->push($this->response('予定どおり。'))->push($this->response('予定[よてい]どおり。'))]);
        $lesson = app(LearnCatalog::class)->find('quartet-1-toori');
        $result = app(GenerateLearnFeedback::class)->handle($lesson, LearnProgress::initial(), ['action' => 'answer', 'text' => '予定どおり。'], 'hana');
        $this->assertSame('予定どおり。', $result['segments'][0]['text']);
        $this->assertArrayNotHasKey('correctedText', $result);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => count($request['input']) === 3 && str_contains($request['input'][2]['content'], 'formatRepair'));
    }
}
