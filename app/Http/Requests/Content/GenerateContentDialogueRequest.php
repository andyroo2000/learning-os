<?php

namespace App\Http\Requests\Content;

use App\Domain\Content\Data\GenerateContentDialogueData;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GenerateContentDialogueRequest extends ConvoLabContentWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $episodeId = $this->input('episodeId');
        $speakers = $this->input('speakers');
        $this->merge([
            'episodeId' => is_string($episodeId) ? strtolower(trim($episodeId)) : $episodeId,
            'speakers' => is_array($speakers) && array_is_list($speakers)
                ? array_map(fn (mixed $speaker): mixed => $this->normalizeSpeaker($speaker), $speakers)
                : $speakers,
            'jlptLevel' => $this->trimString($this->input('jlptLevel')),
            'vocabSeedOverride' => $this->trimString($this->input('vocabSeedOverride')),
            'grammarSeedOverride' => $this->trimString($this->input('grammarSeedOverride')),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->convoLabUserIdRules(),
            'episodeId' => ['required', 'uuid'],
            'speakers' => ['required', 'array', 'size:2'],
            'speakers.*' => ['required', 'array:name,voiceId,proficiency,tone,color'],
            'speakers.*.name' => ['required', 'string', 'max:100'],
            'speakers.*.voiceId' => ['required', 'string', 'max:255'],
            'speakers.*.proficiency' => ['required', 'string', Rule::in([
                'beginner', 'intermediate', 'advanced', 'native', 'N5', 'N4', 'N3', 'N2', 'N1',
            ])],
            'speakers.*.tone' => ['required', 'string', Rule::in(['casual', 'polite', 'formal', 'neutral'])],
            'speakers.*.color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-f]{6}$/i'],
            'variationCount' => ['sometimes', 'required', 'integer', 'between:1,5'],
            'dialogueLength' => ['sometimes', 'required', 'integer', 'between:2,20'],
            'jlptLevel' => ['sometimes', 'nullable', 'string', Rule::in(['N5', 'N4', 'N3', 'N2', 'N1'])],
            'vocabSeedOverride' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'grammarSeedOverride' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $speakers = $this->input('speakers');
            if (! is_array($speakers) || count($speakers) !== 2) {
                return;
            }

            $names = [];
            foreach ($speakers as $speaker) {
                if (! is_array($speaker) || ! is_string($speaker['name'] ?? null)) {
                    return;
                }
                $names[] = mb_strtolower(GenerateContentDialogueData::promptName($speaker['name']));
            }

            if ($names[0] === '' || $names[1] === '' || $names[0] === $names[1]) {
                $validator->errors()->add('speakers', 'Dialogue speaker names must be distinct.');
            }
        }];
    }

    public function generationData(): GenerateContentDialogueData
    {
        $validated = $this->validated();

        return GenerateContentDialogueData::fromInput([
            'episodeId' => $validated['episodeId'],
            'speakers' => $validated['speakers'],
            'variationCount' => array_key_exists('variationCount', $validated) ? (int) $validated['variationCount'] : 3,
            'dialogueLength' => array_key_exists('dialogueLength', $validated) ? (int) $validated['dialogueLength'] : 6,
            'jlptLevel' => $validated['jlptLevel'] ?? null,
            'vocabSeedOverride' => $validated['vocabSeedOverride'] ?? null,
            'grammarSeedOverride' => $validated['grammarSeedOverride'] ?? null,
        ]);
    }

    private function normalizeSpeaker(mixed $speaker): mixed
    {
        if (! is_array($speaker)) {
            return $speaker;
        }

        foreach (['name', 'voiceId', 'proficiency', 'tone', 'color'] as $key) {
            if (array_key_exists($key, $speaker) && is_string($speaker[$key])) {
                $speaker[$key] = trim($speaker[$key]);
            }
        }

        return $speaker;
    }

    private function trimString(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
