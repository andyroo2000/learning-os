<?php

namespace App\Support\Audio;

interface AudioScriptUnit
{
    public function audioType(): string;

    public function audioText(): ?string;

    public function audioVoiceId(): ?string;

    public function audioSpeed(): ?float;

    public function audioPauseSeconds(): ?float;
}
