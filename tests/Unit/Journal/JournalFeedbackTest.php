<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Support\JournalFeedback;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class JournalFeedbackTest extends TestCase
{
    public function test_validates_annotations_in_japanese_and_english_without_changing_text(): void
    {
        $output = JournalFeedbackFixture::result();
        $this->assertSame($output, JournalFeedback::validated('映画を見ますた。', $output));
    }

    #[DataProvider('invalidOutputs')]
    public function test_rejects_unusable_feedback(string $path, mixed $value): void
    {
        $output = JournalFeedbackFixture::result();
        data_set($output, $path, $value);
        try {
            JournalFeedback::validated('映画を見ますた。', $output);
            $this->fail('Invalid feedback should not reach a journal entry.');
        } catch (RuntimeException|ValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }

    public static function invalidOutputs(): array
    {
        return [
            ['segments.0.text', '映画を見ました。'], ['segments.0.text', ''],
            ['segments.0.reading', '映画を見ますた。'],
            ['segments.0.kind', 'unknown'], ['segments.0.suggestion', null],
            ['segments.0.suggestionReading', '映画を見ました。'],
            ['segments.0.explanationReading', 'Use 見ました for the past tense.'],
            ['correctedReading', '映画を見ました。'], ['questionReading', 'どんな映画ですか。'],
            ['questionTranslation', null], ['title', str_repeat('x', 121)],
            ['segments.0.extra', 'untrusted'], ['correctedText', "text\0"],
        ];
    }

    public function test_plain_spaces_and_emoji_can_round_trip_as_separate_segments(): void
    {
        $output = JournalFeedbackFixture::result();
        $plain = ['text' => " \n🍿", 'reading' => " \n🍿", 'kind' => 'plain',
            'suggestion' => null, 'suggestionReading' => null, 'explanation' => null, 'explanationReading' => null];
        $output['segments'][] = $plain;
        $this->assertSame($output, JournalFeedback::validated("映画を見ますた。 \n🍿", $output));
    }
}
