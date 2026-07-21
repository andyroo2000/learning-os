<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Exceptions\StudyPreviewMediaGenerationException;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator as SharedFishAudioSpeechGenerator;

class FishAudioSpeechGenerator implements AudioSpeechGenerator
{
    public const MAX_TEXT_LENGTH = SharedFishAudioSpeechGenerator::MAX_TEXT_LENGTH;

    public const TIMEOUT_SECONDS = SharedFishAudioSpeechGenerator::TIMEOUT_SECONDS;

    public function __construct(private readonly SharedFishAudioSpeechGenerator $speech) {}

    public function generate(string $text, string $voiceId, float $speed = 1.0): string
    {
        try {
            return $this->speech->generate($text, $voiceId, $speed);
        } catch (AudioSpeechGenerationException $exception) {
            throw match ($exception->reason) {
                AudioSpeechGenerationException::UNAVAILABLE => StudyPreviewMediaGenerationException::providerUnavailable('Fish Audio', $exception),
                AudioSpeechGenerationException::RATE_LIMITED => StudyPreviewMediaGenerationException::providerRateLimited('Fish Audio'),
                AudioSpeechGenerationException::INVALID_OUTPUT => StudyPreviewMediaGenerationException::invalidProviderOutput('Fish Audio'),
                default => StudyPreviewMediaGenerationException::providerFailed('Fish Audio', $exception),
            };
        }
    }
}
