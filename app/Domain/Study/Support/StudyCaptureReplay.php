<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Exceptions\CardConflictException;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Data\CaptureStudyCardData;
use Illuminate\Http\UploadedFile;

final class StudyCaptureReplay
{
    public static function find(int $userId, CaptureStudyCardData $data, array $files): ?Card
    {
        $card = Card::withTrashed()->with(['deck' => fn ($query) => $query->withTrashed()])->find($data->id);
        if ($card === null) {
            return null;
        }
        $owner = $card->ownerUserId();
        if ($owner !== $userId) {
            throw CardConflictException::conflict($owner);
        }
        self::assertActive($card, $owner);
        $answer = array_intersect_key($card->answer_json ?? [], $data->answer);
        // PostgreSQL JSONB may reorder object keys; text values must still match exactly.
        ksort($answer);
        $expected = $data->answer;
        ksort($expected);
        $matches = $answer === $expected;
        $matches = $matches && self::matchesFile($card, 'cueAudio', $files['audio']);
        $matches = $matches && self::matchesFile($card, 'cueImage', $files['image'] ?? null);
        if (! $matches) {
            throw CardConflictException::conflict($owner);
        }

        // A transport retry must not upload again or move an already-reviewed card.
        return $card;
    }

    private static function assertActive(Card $card, int $owner): void
    {
        if ($card->trashed()) {
            throw CardConflictException::cardDeleted($owner);
        }
        if ($card->deck->trashed()) {
            throw CardConflictException::deckDeleted($owner);
        }
    }

    private static function matchesFile(Card $card, string $key, ?UploadedFile $file): bool
    {
        $reference = $card->prompt_json[$key] ?? null;
        if ($file === null) {
            return $reference === null;
        }
        if (! is_array($reference)) {
            return false;
        }

        return $card->mediaAssets()
            ->where('media_assets.id', $reference['id'] ?? '')
            ->where('media_assets.user_id', $card->ownerUserId())
            ->where('checksum_sha256', hash_file('sha256', $file->getPathname()))
            ->exists();
    }
}
