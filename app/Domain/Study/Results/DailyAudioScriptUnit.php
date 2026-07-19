<?php

namespace App\Domain\Study\Results;

use InvalidArgumentException;

final readonly class DailyAudioScriptUnit
{
    private const SUPPORTED_TYPES = [
        'marker',
        'narration_L1',
        'pause',
        'L2',
    ];

    private function __construct(
        public string $type,
        public ?string $label = null,
        public ?string $text = null,
        public ?string $reading = null,
        public ?string $translation = null,
        public ?string $voiceId = null,
        public ?float $speed = null,
        public ?float $seconds = null,
    ) {
        $this->validate();
    }

    public static function marker(string $label): self
    {
        return new self(type: 'marker', label: trim($label));
    }

    public static function narration(string $text, string $voiceId): self
    {
        return new self(
            type: 'narration_L1',
            text: trim($text),
            voiceId: trim($voiceId),
        );
    }

    public static function pause(float $seconds): self
    {
        return new self(type: 'pause', seconds: $seconds);
    }

    public static function targetLanguage(
        string $text,
        ?string $reading,
        string $translation,
        string $voiceId,
        float $speed,
    ): self {
        return new self(
            type: 'L2',
            text: trim($text),
            reading: self::nullableTrimmed($reading),
            translation: trim($translation),
            voiceId: trim($voiceId),
            speed: $speed,
        );
    }

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'label' => $this->label,
            'text' => $this->text,
            'reading' => $this->reading,
            'translation' => $this->translation,
            'voiceId' => $this->voiceId,
            'speed' => $this->speed,
            'seconds' => $this->seconds,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function validate(): void
    {
        if (! in_array($this->type, self::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported daily audio script unit.');
        }

        if ($this->type === 'marker' && $this->label === '') {
            throw new InvalidArgumentException('Marker units must include a label.');
        }

        if ($this->type === 'pause' && ($this->seconds === null || $this->seconds <= 0)) {
            throw new InvalidArgumentException('Pause units must have a positive duration.');
        }

        if (in_array($this->type, ['narration_L1', 'L2'], true)) {
            if ($this->text === '') {
                throw new InvalidArgumentException('Spoken units must include text.');
            }
            if ($this->voiceId === '') {
                throw new InvalidArgumentException('Spoken units must include a voice ID.');
            }
        }

        if ($this->type === 'L2' && ($this->speed === null || $this->speed <= 0)) {
            throw new InvalidArgumentException('Target-language units must have a positive speed.');
        }
    }

    private static function nullableTrimmed(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
