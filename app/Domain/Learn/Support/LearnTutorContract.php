<?php

namespace App\Domain\Learn\Support;

use App\Domain\Journal\Support\JournalCompactFeedback;
use App\Domain\Journal\Support\JournalCompactSchema;
use App\Support\Japanese\BracketFurigana;
use Illuminate\Support\Facades\Validator;

final class LearnTutorContract
{
    public static function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'message', 'segments'], 'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['answer', 'clarify', 'acknowledge']],
            'message' => ['type' => 'string'],
            'segments' => JournalCompactSchema::definition()['properties']['segments'],
        ]];
    }

    public static function validate(string $body, string $action, array $output): array
    {
        $data = Validator::make($output, [
            'kind' => [$action === 'message' ? 'required' : 'sometimes', 'in:answer,clarify,acknowledge'],
            'message' => ['required', 'string', 'max:6000'],
            'segments' => ['present', 'array', 'max:100'],
        ])->validate();
        $plainMessage = preg_replace('/([\p{sc=Han}々〆]+)\[([ぁ-ゖァ-ヺー]+)\]/u', '$1', $data['message']);
        BracketFurigana::validate($plainMessage, $data['message']);
        $kind = $action === 'message' ? $data['kind'] : $action;
        if ($kind !== 'answer') {
            return ['kind' => $kind, 'message' => $data['message'], 'segments' => []];
        }
        $validated = JournalCompactFeedback::validated($body, [
            'title' => 'Lesson feedback', 'questionReading' => '理解[りかい]できましたか。',
            'questionTranslation' => 'Does that make sense?', 'segments' => $data['segments'],
        ]);

        // Reuse Journal's exact-source validation, but never expose a polished replacement document.
        return ['kind' => $kind, 'message' => $data['message'], 'segments' => $validated['segments']];
    }
}
