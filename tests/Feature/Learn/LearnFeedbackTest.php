<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Support\LearnTutorContract;
use RuntimeException;
use Tests\TestCase;

class LearnFeedbackTest extends TestCase
{
    private function feedback(string $reading): array
    {
        return ['message' => 'Use the plain form directly before らしい. Does that make sense?', 'segments' => [
            ['reading' => $reading, 'kind' => 'correction', 'suggestionReading' => '来[く]るらしい',
                'explanationReading' => 'Remove んだ before らしい.', 'replacementReading' => '来[く]るらしい'],
        ]];
    }

    public function test_corrections_preserve_original_text_and_never_return_a_polished_document(): void
    {
        $result = LearnTutorContract::validate('来るんだらしい', 'answer', $this->feedback('来[く]るんだらしい'));
        $this->assertSame('来るんだらしい', $result['segments'][0]['text']);
        $this->assertSame('来るらしい', $result['segments'][0]['replacement']);
        $this->assertArrayNotHasKey('correctedText', $result);
        $this->assertArrayNotHasKey('correctedReading', $result);
    }

    public function test_provider_cannot_silently_rewrite_the_original_answer(): void
    {
        $this->expectException(RuntimeException::class);
        LearnTutorContract::validate('来るんだらしい', 'answer', $this->feedback('来[く]るらしい'));
    }

    public function test_questions_get_conversation_without_grammar_markup(): void
    {
        $result = LearnTutorContract::validate('Why?', 'clarify', $this->feedback('Why?'));
        $this->assertSame([], $result['segments']);
    }

    public function test_clarifying_explanations_require_readings_for_every_kanji(): void
    {
        $this->expectException(RuntimeException::class);
        LearnTutorContract::validate('Why?', 'clarify', ['message' => '予定 means plan.', 'segments' => []]);
    }

    public function test_answer_explanations_require_readings_even_when_answer_annotations_are_valid(): void
    {
        $output = $this->feedback('来[く]るんだらしい');
        $output['message'] = 'Use 来る directly.';
        $this->expectException(RuntimeException::class);
        LearnTutorContract::validate('来るんだらしい', 'answer', $output);
    }

    public function test_clarifying_explanations_keep_their_readings_for_the_client(): void
    {
        $message = '予定[よてい] means plan. Does that help?';
        $result = LearnTutorContract::validate('Why?', 'clarify', ['message' => $message, 'segments' => []]);
        $this->assertSame($message, $result['message']);
    }
}
