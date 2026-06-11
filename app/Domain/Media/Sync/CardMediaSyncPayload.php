<?php

namespace App\Domain\Media\Sync;

use App\Support\DateTime\ServerTimestamp;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class CardMediaSyncPayload
{
    public const DOMAIN = 'media';

    public const RESOURCE_TYPE = 'card_media';

    private function __construct() {}

    public static function resourceId(string $cardId, string $mediaAssetId): string
    {
        return "{$cardId}:{$mediaAssetId}";
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromPivot(
        string $cardId,
        string $mediaAssetId,
        ?string $deckId = null,
        ?string $courseId = null,
        Carbon|string|null $createdAt = null,
        Carbon|string|null $updatedAt = null,
    ): array {
        // Delete tombstones may be built without a pivot timestamp snapshot; preserve the keys with null values.
        return [
            'card_id' => $cardId,
            'media_asset_id' => $mediaAssetId,
            'deck_id' => $deckId,
            'course_id' => $courseId,
            'created_at' => self::timestamp($createdAt),
            'updated_at' => self::timestamp($updatedAt),
        ];
    }

    private static function timestamp(Carbon|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ServerTimestamp::toJson($value)
            ?? throw new InvalidArgumentException('Card media timestamp must be a valid server timestamp.');
    }
}
