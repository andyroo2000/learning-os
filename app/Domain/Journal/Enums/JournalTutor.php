<?php

namespace App\Domain\Journal\Enums;

/** Versioned identities: existing questions persist this complete snapshot. */
enum JournalTutor: string
{
    case Hana = 'hana';
    case Ren = 'ren';

    public function presentation(): array
    {
        return ['id' => $this->value, 'name' => $this->name,
            'gender' => $this === self::Hana ? 'F' : 'M', 'avatar' => $this->value.'-v1'];
    }

    public function snapshot(): array
    {
        return [...$this->presentation(), 'voiceId' => $this->voiceId()];
    }

    public function voiceId(): string
    {
        return match ($this) {
            self::Hana => 'fishaudio:9639f090aa6346329d7d3aca7e6b7226', // Yumi, sample 10.
            self::Ren => 'fishaudio:875668667eb94c20b09856b971d9ca2f', // Sato, sample 1.
        };
    }
}
