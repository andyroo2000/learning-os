<?php

namespace App\Domain\Study\Data;

use App\Domain\Study\Exceptions\StudyCardAudioValidationException;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Support\Str;

final readonly class CaptureStudyCardData
{
    public string $id;

    public array $answer;

    public function __construct(string $id, array $text)
    {
        if (! Str::isUlid(trim($id))) {
            throw new StudyCardAudioValidationException('A valid card ID is required.', 'id');
        }
        $this->id = CanonicalUlid::normalize(trim($id));
        $expression = $this->text($text, 'japanese', 2000);
        $meaning = $this->text($text, 'english', 4000);
        $notes = $this->text($text, 'notes', 3000, false);
        $this->answer = [
            'expression' => $expression,
            'meaning' => $meaning,
            'sentenceJp' => $expression,
            'sentenceEn' => $meaning,
            'notes' => $notes,
        ];
    }

    private function text(array $text, string $key, int $limit, bool $required = true): string
    {
        $value = $text[$key] ?? '';
        if (! is_string($value)) {
            throw new StudyCardAudioValidationException("{$key} must be text.", $key);
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new StudyCardAudioValidationException("{$key} is required.", $key);
        }
        if (mb_strlen($value) > $limit) {
            throw new StudyCardAudioValidationException("{$key} must be {$limit} characters or fewer.", $key);
        }

        return $value;
    }
}
