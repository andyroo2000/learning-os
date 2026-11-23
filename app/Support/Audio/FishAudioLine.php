<?php

namespace App\Support\Audio;

use InvalidArgumentException;

final readonly class FishAudioLine
{
    public function __construct(public string $text, public string $voiceId, public float $speed = 1.0) {}

    public function payload(AutomaticSpeechDirections $directions, bool $timed): array
    {
        $text = $this->validatedText($this->text);
        $speed = $this->speed;
        $this->validateSpeed($speed);
        $referenceId = preg_replace('/^fishaudio:/i', '', trim($this->voiceId));
        if (! is_string($referenceId) || preg_match('/^[a-f0-9]{32}$/i', $referenceId) !== 1) {
            throw AudioSpeechGenerationException::unavailable('Fish Audio');
        }

        return [
            'text' => $timed ? $text : $directions->apply($text),
            'reference_id' => $referenceId,
            'format' => 'mp3',
            'mp3_bitrate' => 128,
            'sample_rate' => 44_100,
            'prosody' => ['speed' => floor($speed) === $speed ? (int) $speed : $speed, 'volume' => 0],
        ];
    }

    private function validatedText(string $text): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text, 'UTF-8') > FishAudioSpeechGenerator::MAX_TEXT_LENGTH) {
            throw AudioSpeechGenerationException::invalidOutput('Fish Audio');
        }

        return $text;
    }

    private function validateSpeed(float $speed): void
    {
        if (! is_finite($speed)) {
            throw new InvalidArgumentException('Fish Audio speech speed is invalid.');
        }
        if ($speed < 0.5 || $speed > 2) {
            throw new InvalidArgumentException('Fish Audio speech speed is invalid.');
        }
    }
}
