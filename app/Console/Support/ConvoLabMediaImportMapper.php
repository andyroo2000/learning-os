<?php

namespace App\Console\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

final class ConvoLabMediaImportMapper
{
    public function map(
        ConnectionInterface $source,
        ConnectionInterface $target,
    ): ConvoLabMediaImportMappings {
        $userIds = $this->mapUsers($source, $target);

        return new ConvoLabMediaImportMappings(
            $userIds,
            $this->mapImportJobs($source, $target),
            $this->mapCards($source, $target, $userIds),
        );
    }

    /** @return array<string, int> */
    private function mapUsers(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $referencedSourceUserIds = $source->table('study_media')
            ->pluck('userId')
            ->merge(
                $source->table('study_cards')
                    ->where(function ($query): void {
                        $query->whereNotNull('promptAudioMediaId')
                            ->orWhereNotNull('answerAudioMediaId')
                            ->orWhereNotNull('imageMediaId');
                    })
                    ->pluck('userId'),
            )
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values();
        $targetUsersByEmail = $target->table('users')
            ->get(['id', 'email'])
            ->mapWithKeys(fn (object $user): array => [
                strtolower(trim((string) $user->email)) => (int) $user->id,
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('User')
            ->whereIn('id', $referencedSourceUserIds)
            ->get(['id', 'email']) as $user) {
            $email = strtolower(trim((string) $user->email));
            $targetUserId = $targetUsersByEmail[$email] ?? null;

            if ($targetUserId === null) {
                throw new RuntimeException("Learning OS has no user matching Convo Lab email [{$user->email}].");
            }

            $mapped[(string) $user->id] = $targetUserId;
        }

        return $mapped;
    }

    /** @return array<string, string> */
    private function mapImportJobs(ConnectionInterface $source, ConnectionInterface $target): array
    {
        $targetJobs = $target->table('study_import_jobs')
            ->whereNotNull('convolab_id')
            ->pluck('id', 'convolab_id')
            ->mapWithKeys(fn (mixed $id, mixed $sourceId): array => [
                strtolower((string) $sourceId) => (string) $id,
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('study_media')
            ->whereNotNull('importJobId')
            ->pluck('importJobId')
            ->unique() as $sourceId) {
            $normalized = strtolower(trim((string) $sourceId));

            if (! Str::isUuid($normalized)) {
                throw new RuntimeException("Convo Lab import job [{$sourceId}] does not have a valid UUID.");
            }

            if (! isset($targetJobs[$normalized])) {
                throw new RuntimeException(
                    "Learning OS has no import job matching Convo Lab import job [{$sourceId}].",
                );
            }

            $mapped[(string) $sourceId] = $targetJobs[$normalized];
        }

        return $mapped;
    }

    /**
     * @param  array<string, int>  $userIds
     * @return array<string, array{card_id: string, user_id: int, deck_id: string, course_id: string|null}>
     */
    private function mapCards(
        ConnectionInterface $source,
        ConnectionInterface $target,
        array $userIds,
    ): array {
        $targetCards = $target->table('cards')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereNotNull('cards.convolab_id')
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->get([
                'cards.id',
                'cards.convolab_id',
                'cards.deck_id',
                'decks.user_id',
                'decks.course_id',
            ])
            ->mapWithKeys(fn (object $card): array => [
                strtolower((string) $card->convolab_id) => [
                    'card_id' => (string) $card->id,
                    'user_id' => (int) $card->user_id,
                    'deck_id' => (string) $card->deck_id,
                    'course_id' => $card->course_id === null ? null : (string) $card->course_id,
                ],
            ])
            ->all();
        $mapped = [];

        foreach ($source->table('study_cards')
            ->where(function ($query): void {
                $query->whereNotNull('promptAudioMediaId')
                    ->orWhereNotNull('answerAudioMediaId')
                    ->orWhereNotNull('imageMediaId');
            })
            ->get(['id', 'userId']) as $sourceCard) {
            $sourceId = strtolower(trim((string) $sourceCard->id));
            $targetCard = $targetCards[$sourceId] ?? null;
            $expectedUserId = $userIds[(string) $sourceCard->userId] ?? null;

            if ($targetCard === null) {
                throw new RuntimeException("Learning OS has no card matching Convo Lab card [{$sourceCard->id}].");
            }

            if ($expectedUserId === null || $targetCard['user_id'] !== $expectedUserId) {
                throw new RuntimeException("Convo Lab card [{$sourceCard->id}] does not match the Learning OS owner.");
            }

            $mapped[(string) $sourceCard->id] = $targetCard;
        }

        return $mapped;
    }
}
