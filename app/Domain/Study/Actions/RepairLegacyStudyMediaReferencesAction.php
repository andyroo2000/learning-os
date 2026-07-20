<?php

namespace App\Domain\Study\Actions;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Sync\CardSyncPayload;
use App\Domain\Study\Results\StudyMediaReferenceRepairResult;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

final class RepairLegacyStudyMediaReferencesAction
{
    private const CHUNK_SIZE = 250;

    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    /**
     * @param  list<string>|null  $cardIds
     */
    public function handle(
        ConnectionInterface $connection,
        bool $apply,
        ?array $cardIds = null,
    ): StudyMediaReferenceRepairResult {
        $result = new StudyMediaReferenceRepairResult;

        if ($cardIds !== null) {
            foreach (array_chunk(array_values(array_unique($cardIds)), self::CHUNK_SIZE) as $chunk) {
                $result->add($this->repairChunk($connection, $chunk, $apply));
            }

            return $result;
        }

        $lastId = null;

        do {
            $query = $connection->table('cards')
                ->select('cards.id')
                ->join('decks', 'decks.id', '=', 'cards.deck_id')
                ->whereNull('cards.deleted_at')
                ->whereNull('decks.deleted_at')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('card_media')
                        ->whereColumn('card_media.card_id', 'cards.id');
                })
                ->orderBy('cards.id')
                ->limit(self::CHUNK_SIZE);

            if ($lastId !== null) {
                $query->where('cards.id', '>', $lastId);
            }

            $ids = $query->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

            if ($ids === []) {
                break;
            }

            $result->add($this->repairChunk($connection, $ids, $apply));
            $lastId = $ids[array_key_last($ids)];
        } while (count($ids) === self::CHUNK_SIZE);

        return $result;
    }

    /**
     * @param  list<string>  $cardIds
     */
    private function repairChunk(
        ConnectionInterface $connection,
        array $cardIds,
        bool $apply,
    ): StudyMediaReferenceRepairResult {
        $callback = fn (): StudyMediaReferenceRepairResult => $this->repairLockedChunk(
            $connection,
            $cardIds,
            $apply,
        );

        if (! $apply || $connection->transactionLevel() > 0) {
            return $callback();
        }

        return $connection->transaction($callback);
    }

    /**
     * @param  list<string>  $cardIds
     */
    private function repairLockedChunk(
        ConnectionInterface $connection,
        array $cardIds,
        bool $apply,
    ): StudyMediaReferenceRepairResult {
        $cardsQuery = $connection->table('cards')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->select([
                'cards.*',
                'decks.user_id as owner_user_id',
                'decks.course_id as deck_course_id',
            ])
            ->whereIn('cards.id', $cardIds)
            ->whereNull('cards.deleted_at')
            ->whereNull('decks.deleted_at')
            ->orderBy('cards.id');

        if ($apply) {
            $cardsQuery->lockForUpdate();
        }

        $cards = $cardsQuery->get();
        $assetsByCard = $this->assetsByCard($connection, $cards->pluck('id')->all());
        $result = new StudyMediaReferenceRepairResult(cardsScanned: $cards->count());

        foreach ($cards as $card) {
            $assets = $assetsByCard->get((string) $card->id, collect());
            $candidates = $this->mediaCandidates($assets);
            $prompt = $this->decodePayload($card->prompt_json, 'prompt_json', (string) $card->id);
            $answer = $this->decodePayload($card->answer_json, 'answer_json', (string) $card->id);
            $cardResult = new StudyMediaReferenceRepairResult;
            $repairedPrompt = $this->repairValue($prompt, $candidates, $cardResult);
            $repairedAnswer = $this->repairValue($answer, $candidates, $cardResult);

            $result->referencesChanged += $cardResult->referencesChanged;
            $result->unmatchedReferences += $cardResult->unmatchedReferences;
            $result->ambiguousReferences += $cardResult->ambiguousReferences;

            if ($repairedPrompt === $prompt && $repairedAnswer === $answer) {
                continue;
            }

            $result->cardsChanged++;

            if (! $apply) {
                continue;
            }

            $connection->table('cards')
                ->where('id', $card->id)
                ->update([
                    'prompt_json' => $this->encodePayload($repairedPrompt),
                    'answer_json' => $this->encodePayload($repairedAnswer),
                ]);

            $cardModel = (new Card)->newFromBuilder((array) $card, $connection->getName());
            $cardModel->setAttribute('prompt_json', $repairedPrompt);
            $cardModel->setAttribute('answer_json', $repairedAnswer);

            $this->recordSyncFeedEntry->handle(
                RecordSyncFeedEntryData::fromInput(
                    userId: $cardModel->ownerUserId(),
                    domain: CardSyncPayload::DOMAIN,
                    resourceType: CardSyncPayload::RESOURCE_TYPE,
                    resourceId: $cardModel->id,
                    operation: SyncFeedOperation::Update->value,
                    payload: CardSyncPayload::fromCard($cardModel),
                ),
            );
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $cardIds
     * @return Collection<string, Collection<int, object>>
     */
    private function assetsByCard(ConnectionInterface $connection, array $cardIds): Collection
    {
        if ($cardIds === []) {
            return collect();
        }

        return $connection->table('card_media')
            ->join('cards', 'cards.id', '=', 'card_media.card_id')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->join('media_assets', 'media_assets.id', '=', 'card_media.media_asset_id')
            ->whereIn('card_media.card_id', $cardIds)
            ->whereColumn('media_assets.user_id', 'decks.user_id')
            ->orderBy('card_media.card_id')
            ->orderBy('media_assets.id')
            ->get([
                'card_media.card_id',
                'media_assets.id',
                'media_assets.path',
                'media_assets.mime_type',
                'media_assets.original_filename',
                'media_assets.source_filename',
            ])
            ->groupBy(static fn (object $row): string => (string) $row->card_id);
    }

    /**
     * @param  Collection<int, object>  $assets
     * @return array<string, array{id: string}|null>
     */
    private function mediaCandidates(Collection $assets): array
    {
        $candidates = [];

        foreach ($assets as $asset) {
            $kind = $this->mediaKind((string) $asset->mime_type);

            if ($kind === null) {
                continue;
            }

            $filenames = array_unique(array_filter(
                [
                    $this->normalizedFilename($asset->source_filename),
                    $this->normalizedFilename($asset->original_filename),
                    $this->normalizedFilename(basename((string) $asset->path)),
                ],
                static fn (?string $filename): bool => $filename !== null,
            ));

            foreach ($filenames as $filename) {
                $key = $kind."\0".$filename;
                $candidate = ['id' => (string) $asset->id];

                if (array_key_exists($key, $candidates) && $candidates[$key] !== $candidate) {
                    $candidates[$key] = null;
                } elseif (! array_key_exists($key, $candidates)) {
                    $candidates[$key] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, array{id: string}|null>  $candidates
     */
    private function repairValue(
        mixed $value,
        array $candidates,
        StudyMediaReferenceRepairResult $result,
    ): mixed {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isLegacyMediaReference($value)) {
            $kind = (string) $value['mediaKind'];
            $filename = $this->normalizedFilename($value['filename']);
            $key = $kind."\0".$filename;

            if (! array_key_exists($key, $candidates)) {
                $result->unmatchedReferences++;

                return $value;
            }

            $candidate = $candidates[$key];

            if ($candidate === null) {
                $result->ambiguousReferences++;

                return $value;
            }

            $url = "/api/study/media/{$candidate['id']}";

            if (($value['id'] ?? null) === $candidate['id'] && ($value['url'] ?? null) === $url) {
                return $value;
            }

            $result->referencesChanged++;

            return [
                ...$value,
                'id' => $candidate['id'],
                'url' => $url,
            ];
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->repairValue($child, $candidates, $result);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isLegacyMediaReference(array $value): bool
    {
        $kind = $value['mediaKind'] ?? null;
        $filename = $this->normalizedFilename($value['filename'] ?? null);

        if (! in_array($kind, ['audio', 'image'], true) || $filename === null) {
            return false;
        }

        $id = $value['id'] ?? null;

        if (is_string($id) && trim($id) !== '' && ! Str::isUlid(trim($id))) {
            return true;
        }

        $url = $value['url'] ?? null;

        if (! is_string($url)) {
            return false;
        }

        if (preg_match('~^/api/study/media/([^/?#]+)$~', trim($url), $matches) !== 1) {
            return false;
        }

        return ! Str::isUlid($matches[1]);
    }

    private function mediaKind(string $mimeType): ?string
    {
        return match (true) {
            str_starts_with(strtolower($mimeType), 'audio/') => 'audio',
            str_starts_with(strtolower($mimeType), 'image/') => 'image',
            default => null,
        };
    }

    private function normalizedFilename(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodePayload(mixed $value, string $column, string $cardId): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException("Card [{$cardId}] has an invalid {$column} value.");
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Card [{$cardId}] has invalid {$column} JSON.", previous: $e);
        }

        if ($decoded !== null && ! is_array($decoded)) {
            throw new RuntimeException("Card [{$cardId}] has a non-object {$column} payload.");
        }

        return $decoded;
    }

    private function encodePayload(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Unable to encode repaired card media references.', previous: $e);
        }
    }
}
