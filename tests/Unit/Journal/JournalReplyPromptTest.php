<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\GenerateJournalFeedback;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JournalReplyPromptTest extends TestCase
{
    public function test_followup_context_is_quoted_data_and_output_is_validated_against_the_answer(): void
    {
        config(['services.openai.api_key' => 'test']);
        $result = JournalFeedbackFixture::result();
        Http::fake(['*' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]],
        ]])]);
        $context = ['diary' => '映画の日。', 'question' => '何をしましたか。'];
        $this->assertSame($result, app(GenerateJournalFeedback::class)->handle('映画を見ますた。', $context));
        Http::assertSent(fn ($request) => json_decode($request['input'][1]['content'], true) === ['body' => '映画を見ますた。', 'context' => $context]);
    }
}
