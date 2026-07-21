<?php

namespace App\Domain\Content\Results;

use App\Support\Audio\AudioScriptUnit;

final readonly class ContentDialogueAudioScriptUnit implements AudioScriptUnit
{
    private function __construct(
        public string $type,
        public ?string $text,
        public ?string $voiceId,
        public ?float $speed,
        public ?float $seconds,
    ) {}

    public static function spoken(string $text, string $voiceId, float $speed): self
    {
        return new self('L2', $text, $voiceId, $speed, null);
    }

    public static function pause(float $seconds): self
    {
        return new self('pause', null, null, null, $seconds);
    }

    public function audioType(): string
    {
        return $this->type;
    }

    public function audioText(): ?string
    {
        return $this->text;
    }

    public function audioVoiceId(): ?string
    {
        return $this->voiceId;
    }

    public function audioSpeed(): ?float
    {
        return $this->speed;
    }

    public function audioPauseSeconds(): ?float
    {
        return $this->seconds;
    }
}
