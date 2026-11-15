<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\GenerateJournalFeedback;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JournalExplanationPromptTest extends TestCase
{
    public function test_emphasis_and_readings_survive_generation_without_changing_the_wire_contract(): void
    {
        config(['services.openai.api_key' => 'test']);
        $result = JournalFeedbackFixture::result();
        $result['segments'][0]['explanation'] = 'Use 見ました for the ***past tense***.';
        $result['segments'][0]['explanationReading'] = 'Use 見[み]ました for the ***past tense***.';
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]],
        ]])]);

        $this->assertSame($result, app(GenerateJournalFeedback::class)->handle('映画を見ますた。'));
        Http::assertSent(function ($request): bool {
            $prompt = $request['input'][0]['content'];

            return str_contains($prompt, 'Do not repeat kana readings in parentheses')
                && str_contains($prompt, 'Keep full bracket furigana in explanationReading')
                && str_contains($prompt, 'Keep identical emphasis markers in both fields')
                && str_contains($prompt, 'Do not use HTML, links, images')
                && str_contains($prompt, 'Never put emphasis markup in Japanese source');
        });
    }
}
