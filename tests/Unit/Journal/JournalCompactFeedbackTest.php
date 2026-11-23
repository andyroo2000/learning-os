<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Services\CheckJournalMeaning;
use App\Domain\Journal\Services\GenerateJournalFeedback;
use App\Domain\Journal\Support\JournalCombinedResult;
use App\Domain\Journal\Support\JournalCompactFeedback;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class JournalCompactFeedbackTest extends TestCase
{
    public function test_compact_output_reconstructs_full_explanations_readings_and_replacements(): void
    {
        $result = JournalCompactFeedback::validated('映画を見ますた。', CompactFeedbackFixture::result());
        $this->assertSame('映画を見ました。', $result['correctedText']);
        $this->assertSame('映画[えいが]を見[み]ました。', $result['correctedReading']);
        $this->assertSame('映画を見ますた。', $result['segments'][0]['text']);
        $this->assertSame('Use 見ました for the past tense.', $result['segments'][0]['explanation']);
        $this->assertSame(CompactFeedbackFixture::result()['segments'][0]['explanationReading'], $result['segments'][0]['explanationReading']);
        $this->assertSame('映画を見ました。', $result['segments'][0]['suggestion']);
        $this->assertSame('どうでしたか。', $result['question']);
    }

    public function test_optional_wording_is_applied_and_whitespace_emoji_and_deletions_survive(): void
    {
        $data = CompactFeedbackFixture::result();
        $data['segments'][0]['kind'] = 'optional';
        $data['segments'][] = ['reading' => "\n\n😊 [note] ", 'kind' => 'plain', 'suggestionReading' => null,
            'explanationReading' => null, 'replacementReading' => null];
        $data['segments'][] = ['reading' => '。。', 'kind' => 'correction', 'suggestionReading' => '映画[えいが]を見[み]ました。',
            'explanationReading' => 'Remove repeated punctuation.', 'replacementReading' => ''];
        $body = "映画を見ますた。\n\n😊 [note] 。。";
        $result = JournalCompactFeedback::validated($body, $data);
        $this->assertSame("映画を見ました。\n\n😊 [note] ", $result['correctedText']);
        $this->assertSame('', $result['segments'][2]['replacement']);
        $this->assertSame($body, implode('', array_column($result['segments'], 'text')));
    }

    public function test_changed_source_text_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        JournalCompactFeedback::validated('映画を見ません。', CompactFeedbackFixture::result());
    }

    public function test_missing_furigana_is_rejected(): void
    {
        $data = CompactFeedbackFixture::result();
        $data['segments'][0]['replacementReading'] = '映画を見ました。';
        $this->expectException(RuntimeException::class);
        JournalCompactFeedback::validated('映画を見ますた。', $data);
    }

    public function test_feedback_is_forbidden_while_a_clarification_is_unresolved(): void
    {
        $this->expectException(RuntimeException::class);
        JournalCombinedResult::validated('映画を見ますた。', ['resolved' => false,
            'question' => ['prompt' => 'Who?', 'choices' => ['Me', 'Molly']], 'confirmedMeaning' => '',
            'feedback' => CompactFeedbackFixture::result()]);
    }

    public function test_provider_requests_keep_model_reasoning_and_complete_quality_instructions(): void
    {
        config(['services.openai.api_key' => 'test', 'journal.model' => 'gpt-6.1-sol', 'journal.reasoning_effort' => 'medium']);
        Http::fakeSequence()->push(self::response(CompactFeedbackFixture::result()))->push(self::response([
            'resolved' => true, 'question' => null, 'confirmedMeaning' => '', 'feedback' => CompactFeedbackFixture::result(),
        ]));
        $feedback = app(GenerateJournalFeedback::class)->handle('映画を見ますた。');
        $combined = app(CheckJournalMeaning::class)->handle('映画を見ますた。');
        $this->assertSame($feedback, $combined['feedback']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('gpt-6.1-sol', $request['model']);
            $this->assertSame('medium', $request['reasoning']['effort']);
            $this->assertFalse($request['store']);
            $this->assertNotEmpty($request['prompt_cache_key']);
            $this->assertStringContainsString('Do not manufacture corrections', $request['input'][0]['content']);
            $this->assertStringContainsString('Preserve proper nouns and quoted titles exactly', $request['input'][0]['content']);
            $this->assertStringContainsString('Do not abbreviate explanations', $request['input'][0]['content']);
        }
    }

    public static function response(array $result): array
    {
        return ['status' => 'completed', 'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($result)]]]]];
    }
}
