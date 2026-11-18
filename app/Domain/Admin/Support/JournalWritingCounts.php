<?php

namespace App\Domain\Admin\Support;

final class JournalWritingCounts
{
    public int $originals = 0;

    public int $followups = 0;

    private int $originalCharacters = 0;

    private int $followupCharacters = 0;

    private array $users = [];

    public function add(object $event): void
    {
        $this->users[$event->user_id] = true;
        if ($event->kind === 'original') {
            $this->originals++;
            $this->originalCharacters += $event->characters;
        } else {
            $this->followups++;
            $this->followupCharacters += $event->characters;
        }
    }

    public function toArray(): array
    {
        return ['originals' => $this->originals, 'followups' => $this->followups,
            'activeUsers' => count($this->users),
            'averageEntryCharacters' => $this->originals === 0 ? null : $this->originalCharacters / $this->originals,
            'averageFollowupCharacters' => $this->followups === 0 ? null : $this->followupCharacters / $this->followups];
    }
}
