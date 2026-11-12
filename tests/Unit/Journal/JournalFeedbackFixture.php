<?php

namespace Tests\Unit\Journal;

final class JournalFeedbackFixture
{
    public static function result(): array
    {
        return [
            'title' => 'A movie night',
            'correctedText' => '映画を見ました。', 'correctedReading' => '映画[えいが]を見[み]ました。',
            'question' => 'どんな映画ですか。', 'questionReading' => 'どんな映画[えいが]ですか。',
            'questionTranslation' => 'What kind of movie is it?',
            'segments' => [[
                'text' => '映画を見ますた。', 'reading' => '映画[えいが]を見[み]ますた。', 'kind' => 'correction',
                'suggestion' => '映画を見ました。', 'suggestionReading' => '映画[えいが]を見[み]ました。',
                'explanation' => 'Use 見ました for the past tense.',
                'explanationReading' => 'Use 見[み]ました for the past tense.',
            ]],
        ];
    }
}
