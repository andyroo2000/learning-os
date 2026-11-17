<?php

namespace App\Domain\Journal\Support;

use App\Support\Japanese\BracketFurigana;
use Illuminate\Support\Facades\Validator;

final class JournalCompactFeedback
{
    public static function validated(string $body, array $output): array
    {
        if (array_key_exists('correctedText', $output)) {
            return JournalFeedback::validated($body, $output); // Existing stored/provider fixtures stay readable.
        }
        $data = Validator::make($output, [
            'title' => ['required', 'string', 'max:120'],
            'questionReading' => ['required', 'string', 'max:4000'],
            'questionTranslation' => ['required', 'string', 'max:2000'],
            'segments' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'segments.*.reading' => ['present', 'string', 'max:80000'],
            'segments.*.kind' => ['required', 'in:plain,correction,optional'],
            'segments.*.suggestionReading' => ['present', 'nullable', 'string', 'max:12000'],
            'segments.*.explanationReading' => ['present', 'nullable', 'string', 'max:8000'],
            'segments.*.replacementReading' => ['present', 'nullable', 'string', 'max:80000'],
        ])->validate();
        $data['question'] = self::plain($data['questionReading']);
        $data['segments'] = array_map(self::expandSegment(...), $data['segments']);
        $data['correctedText'] = $body;
        $data['correctedReading'] = implode('', array_column($data['segments'], 'reading'));

        // The existing validator proves exact source reconstruction and annotation completeness,
        // then builds the final document from correction spans, leaving optional wording untouched.
        return JournalFeedback::validated($body, $data);
    }

    private static function expandSegment(array $segment): array
    {
        foreach (['text' => 'reading', 'suggestion' => 'suggestionReading',
            'explanation' => 'explanationReading', 'replacement' => 'replacementReading'] as $text => $reading) {
            $segment[$text] = self::plain($segment[$reading]);
        }

        return $segment;
    }

    private static function plain(?string $reading): ?string
    {
        if ($reading === null) {
            return null;
        }
        $text = preg_replace('/([\p{sc=Han}々〆]+)\[([ぁ-ゖァ-ヺー]+)\]/u', '$1', $reading);
        BracketFurigana::validate($text, $reading);

        return $text;
    }
}
