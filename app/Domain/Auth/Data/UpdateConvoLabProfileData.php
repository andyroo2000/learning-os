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

        foreach (self::FIELD_MAP as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $attributes[$column] = $validated[$input];
            }
        }

        return new self($attributes);
    }
}
