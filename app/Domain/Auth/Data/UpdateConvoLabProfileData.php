<?php

namespace App\Domain\Auth\Data;

final readonly class UpdateConvoLabProfileData
{
    public const FIELD_MAP = [
        'displayName' => 'display_name',
        'avatarColor' => 'avatar_color',
        'avatarUrl' => 'avatar_url',
        'preferredStudyLanguage' => 'preferred_study_language',
        'preferredNativeLanguage' => 'preferred_native_language',
        'proficiencyLevel' => 'proficiency_level',
        'onboardingCompleted' => 'onboarding_completed',
        'seenSampleContentGuide' => 'seen_sample_content_guide',
        'seenCustomContentGuide' => 'seen_custom_content_guide',
    ];

    /** @param array<string, mixed> $attributes */
    private function __construct(public array $attributes) {}

    /** @param array<string, mixed> $validated */
    public static function fromValidated(array $validated): self
    {
        $attributes = [];
        $booleanFields = [
            'onboardingCompleted',
            'seenSampleContentGuide',
            'seenCustomContentGuide',
        ];

        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $value = $validated[$input];
                if (in_array($input, $booleanFields, true)) {
                    $value = self::normalizeBoolean($input, $value);
                }

                $attributes[$column] = $value;
            }
        }

        return new self($attributes);
    }

    private static function normalizeBoolean(string $field, mixed $value): bool
    {
        if (in_array($value, [true, 1, '1'], true)) {
            return true;
        }
        if (in_array($value, [false, 0, '0'], true)) {
            return false;
        }

        throw new \InvalidArgumentException($field.' must be a boolean.');
    }
}
