<?php

namespace App\Domain\Admin\Support;

use App\Domain\Admin\Data\GenerateAdminSentenceScriptData;

final class AdminSentenceScriptPrompt
{
    private const DEFAULT_TEMPLATE = <<<'PROMPT'
You are a language teaching expert creating a Pimsleur-style audio lesson script.

Target language (L2): {{targetLanguage}}
Native language (L1): {{nativeLanguage}}
Learner proficiency: {{jlptLevel}}
Sentence (L2): "{{sentence}}"
Translation (L1): "{{translation}}"
If the translation is empty, translate the sentence into {{nativeLanguage}} and include it in the response.

Create a concise teaching sequence:
1. Present the full sentence twice with a 3-second pause.
2. Explain its translation.
3. Teach 2-3 useful content words or chunks in right-to-left order, skipping trivial vocabulary.
4. Build toward the full sentence with 2-4 prompted-recall steps using natural, complete phrases.

Return only one JSON object with keys "translation" and "units". Allowed unit shapes are:
- {"type":"narration_L1","text":"..."}
- {"type":"L2","text":"...","reading":"hiragana when L2 is Japanese","speed":1.0}
- {"type":"pause","seconds":3 to 7}

Every L2 answer must appear twice with a 3-second pause between repetitions. Use recall pauses of 3 seconds for a word, 5 seconds for a phrase, and 7 seconds for a long phrase or sentence. Keep narration concise. Do not include markdown or additional keys.
PROMPT;

    public static function resolve(GenerateAdminSentenceScriptData $data): string
    {
        $template = $data->promptOverride ?? self::DEFAULT_TEMPLATE;
        $values = [
            'sentence' => $data->sentence,
            'translation' => $data->translation ?? '',
            'targetLanguage' => $data->targetLanguage,
            'nativeLanguage' => $data->nativeLanguage,
            'jlptLevel' => $data->jlptLevel ?? '',
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $match): string => $values[$match[1]] ?? '',
            $template,
        );
    }
}
