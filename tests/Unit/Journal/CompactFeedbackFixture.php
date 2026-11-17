<?php

namespace Tests\Unit\Journal;

final class CompactFeedbackFixture
{
    public static function result(): array
    {
        return ['title' => '映画', 'questionReading' => 'どうでしたか。', 'questionTranslation' => 'How was it?',
            'segments' => [[
                'reading' => '映画[えいが]を見[み]ますた。', 'kind' => 'correction',
                'suggestionReading' => '映画[えいが]を見[み]ました。',
                'explanationReading' => 'Use 見[み]ました for the past tense.',
                'replacementReading' => '映画[えいが]を見[み]ました。',
            ]]];
    }
}
