<?php

namespace App\Support\Rehearsal;

use Illuminate\Database\ConnectionInterface;

class ConvoLabCardMediaImporter
{
    /**
     * @param  array<string, string>  $cardIds
     * @param  array<string, string>  $mediaIds
     * @param  array<string, int>  $mediaUserIds
     * @param  array<string, int>  $userIds
     */
    public function __construct(
        private readonly array $cardIds,
        private readonly array $mediaIds,
        private readonly array $mediaUserIds,
        private readonly array $userIds,
    ) {}

    public function import(ConnectionInterface $source, ConnectionInterface $target): int
    {
        $count = 0;

        $source->table('study_cards')
            ->select('id', 'userId', 'promptAudioMediaId', 'answerAudioMediaId', 'imageMediaId', 'createdAt', 'updatedAt')
            ->orderBy('id')
            ->chunk(500, function ($cards) use ($target, &$count): void {
                $rows = [];

                foreach ($cards as $card) {
                    foreach ($this->rowsForCard($card) as $key => $row) {
                        $rows[$key] = $row;
                    }
                }

                if ($rows !== []) {
                    $target->table('card_media')->insert(array_values($rows));
                }

                $count += count($rows);
            });

        return $count;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsForCard(object $card): array
    {
        $rows = [];

        foreach ([$card->promptAudioMediaId, $card->answerAudioMediaId, $card->imageMediaId] as $sourceMediaId) {
            if ($sourceMediaId === null || $sourceMediaId === '') {
                continue;
            }

            [$key, $row] = $this->rowForMedia($card, $sourceMediaId);
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function rowForMedia(object $card, mixed $sourceMediaId): array
    {
        if (! is_string($sourceMediaId) || ! isset($this->mediaIds[$sourceMediaId])) {
            throw new \RuntimeException("Missing imported media mapping for [{$sourceMediaId}].");
        }

        $cardUserId = $this->userIds[$card->userId]
            ?? throw new \RuntimeException("Missing imported user mapping for [{$card->userId}].");

        if (($this->mediaUserIds[$sourceMediaId] ?? null) !== $cardUserId) {
            throw new \RuntimeException(
                "Card [{$card->id}] references media [{$sourceMediaId}] owned by another user.",
            );
        }

        $cardId = $this->cardIds[$card->id];
        $mediaId = $this->mediaIds[$sourceMediaId];

        return [
            $cardId.':'.$mediaId,
            [
                'card_id' => $cardId,
                'media_asset_id' => $mediaId,
                'created_at' => $card->createdAt,
                'updated_at' => $card->updatedAt,
            ],
        ];
    }
}
