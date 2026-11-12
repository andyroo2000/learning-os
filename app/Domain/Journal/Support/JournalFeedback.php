<?php

namespace App\Domain\Journal\Support;

use App\Support\Japanese\BracketFurigana;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class JournalFeedback
{
    public static function validated(string $original, array $output): array
    {
        $text = ['required', 'string', 'not_regex:/\x00/'];
        $data = Validator::make($output, [
            'title' => [...$text, 'max:120'],
            'correctedText' => [...$text, 'max:30000'],
            'correctedReading' => [...$text, 'max:120000'],
            'question' => [...$text, 'max:1000'],
            'questionReading' => [...$text, 'max:4000'],
            'questionTranslation' => [...$text, 'max:2000'],
            'segments' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'segments.*' => ['array:text,reading,kind,suggestion,suggestionReading,explanation,explanationReading,replacement,replacementReading'],
        ])->validate();
        $reconstructed = '';
        foreach ($data['segments'] as $index => $segment) {
            $data['segments'][$index] = self::segment($segment);
            $reconstructed .= $segment['text'];
        }
        if ($reconstructed !== $original) {
            throw new RuntimeException('Journal highlights must preserve every original character.');
        }
        BracketFurigana::validate($data['correctedText'], $data['correctedReading']);
        BracketFurigana::validate($data['question'], $data['questionReading']);

        return JournalReplacements::prepare($data);
    }

    private static function segment(array $segment): array
    {
        $note = ['present', 'nullable', 'required_unless:kind,plain', 'string', 'not_regex:/\x00/'];
        $data = Validator::make($segment, [
            'text' => ['present', 'string', 'max:20000'],
            'reading' => ['present', 'string', 'max:80000'],
            'kind' => ['required', 'in:plain,correction,optional'],
            'suggestion' => [...$note, 'max:3000'],
            'suggestionReading' => [...$note, 'max:12000'],
            'explanation' => [...$note, 'max:2000'],
            'explanationReading' => [...$note, 'max:8000'],
            'replacement' => ['sometimes', 'nullable', 'string', 'max:20000', 'not_regex:/\x00/'],
            'replacementReading' => ['sometimes', 'nullable', 'string', 'max:80000', 'not_regex:/\x00/'],
        ])->validate();
        if ($data['text'] === '') {
            throw new RuntimeException('Journal highlights cannot contain empty spans.');
        }
        self::validatePlainSegment($data);
        foreach (['text' => 'reading', 'suggestion' => 'suggestionReading', 'explanation' => 'explanationReading'] as $text => $reading) {
            self::validateReadingPair($data[$text], $data[$reading]);
        }

        return $data;
    }

    private static function validatePlainSegment(array $data): void
    {
        if ($data['kind'] !== 'plain') {
            return;
        }
        foreach (['suggestion', 'suggestionReading', 'explanation', 'explanationReading'] as $field) {
            if ($data[$field] !== null) {
                throw new RuntimeException('Plain journal segments cannot contain feedback notes.');
            }
        }
    }

    private static function validateReadingPair(?string $text, ?string $reading): void
    {
        if ($text !== null) {
            BracketFurigana::validate($text, $reading);
        } elseif ($reading !== null) {
            throw new RuntimeException('A reading cannot exist without its source text.');
        }
    }
}
