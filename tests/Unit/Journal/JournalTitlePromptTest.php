<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\GenerateJournalFeedback;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JournalTitlePromptTest extends TestCase
{
    public function test_generation_requests_a_learner_level_japanese_title_and_preserves_the_returned_japanese(): void
    {
        config(['services.openai.api_key' => 'test']);
        $result = JournalFeedbackFixture::result();
        $result['title'] = '映画を見た日';
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]],
        ]])]);

        $feedback = app(GenerateJournalFeedback::class)->handle('映画を見ますた。');
        $this->assertSame('映画を見た日', $feedback['title']);
        // Pin the prompt contract; the fake does not evaluate the model's title quality.
        Http::assertSent(function ($request): bool {
            $prompt = $request['input'][0]['content'];

            return str_contains($prompt, 'natural JAPANESE diary title')
                && str_contains($prompt, "Estimate the writer's level from the ORIGINAL diary")
                && str_contains($prompt, 'Preserve that name exactly as written')
                && ! str_contains($prompt, 'descriptive ENGLISH title');
        });
    }
}
