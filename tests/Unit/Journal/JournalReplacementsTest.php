<?php

namespace Tests\Unit\Journal;

use App\Domain\Journal\Support\JournalFeedback;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class JournalReplacementsTest extends TestCase
{
    public function test_only_corrections_are_applied_and_readings_follow_the_same_spans(): void
    {
        $feedback = $this->feedback();
        $output = JournalFeedback::validated('映画を見ますた。よかった。', $feedback);
        $this->assertSame('映画を見ました。よかった。', $output['correctedText']);
        $this->assertSame('映画[えいが]を見[み]ました。よかった。', $output['correctedReading']);
        $this->assertSame(1, $output['editVersion']);
        $this->assertSame('楽しかった。', $output['segments'][1]['replacement']);
    }

    public function test_deletions_are_explicit_empty_replacements(): void
    {
        $feedback = $this->feedback();
        $feedback['segments'][1]['kind'] = 'correction';
        $feedback['segments'][1]['replacement'] = '';
        $feedback['segments'][1]['replacementReading'] = '';
        $output = JournalFeedback::validated('映画を見ますた。よかった。', $feedback);
        $this->assertSame('映画を見ました。', $output['correctedText']);
    }

    #[DataProvider('invalidReplacements')]
    public function test_rejects_incomplete_or_unusable_replacements(string $field, mixed $value): void
    {
        $feedback = $this->feedback();
        $feedback['segments'][0][$field] = $value;
        $this->expectException(RuntimeException::class);
        JournalFeedback::validated('映画を見ますた。よかった。', $feedback);
    }

    public static function invalidReplacements(): array
    {
        return [['replacement', null], ['replacementReading', null], ['replacementReading', '映画を見ました。'],
            ['replacementReading', '映画[えいが]を見[み]た。']];
    }

    public function test_mixing_legacy_and_editable_spans_cannot_enable_partial_controls(): void
    {
        $feedback = $this->feedback();
        unset($feedback['segments'][1]['replacement']);
        $this->expectException(RuntimeException::class);
        JournalFeedback::validated('映画を見ますた。よかった。', $feedback);
    }

    public function test_plain_text_is_preserved_and_cannot_have_a_hidden_replacement(): void
    {
        $feedback = $this->feedback();
        $feedback['segments'][] = ['kind' => 'plain', 'text' => ' 🍿', 'reading' => ' 🍿',
            'suggestion' => null, 'suggestionReading' => null, 'explanation' => null, 'explanationReading' => null,
            'replacement' => null, 'replacementReading' => null];
        $output = JournalFeedback::validated('映画を見ますた。よかった。 🍿', $feedback);
        $this->assertStringEndsWith(' 🍿', $output['correctedText']);
        $feedback['segments'][2]['replacement'] = 'other';
        $this->expectException(RuntimeException::class);
        JournalFeedback::validated('映画を見ますた。よかった。 🍿', $feedback);
    }

    private function feedback(): array
    {
        $feedback = JournalFeedbackFixture::result();
        $feedback['segments'][0]['replacement'] = '映画を見ました。';
        $feedback['segments'][0]['replacementReading'] = '映画[えいが]を見[み]ました。';
        $feedback['segments'][] = ['kind' => 'optional', 'text' => 'よかった。', 'reading' => 'よかった。',
            'suggestion' => '楽しかった。', 'suggestionReading' => '楽[たの]しかった。',
            'explanation' => 'The original is fine. This emphasizes enjoyment.',
            'explanationReading' => 'The original is fine. This emphasizes enjoyment.',
            'replacement' => '楽しかった。', 'replacementReading' => '楽[たの]しかった。'];

        return $feedback;
    }
}
