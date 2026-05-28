<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Data\AttachMediaToCardData;
use App\Domain\Media\Exceptions\CannotAttachMediaToCard;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttachMediaToCardDataTest extends TestCase
{
    public function test_it_rejects_invalid_card_ulid(): void
    {
        $this->expectException(CannotAttachMediaToCard::class);
        $this->expectExceptionMessage('Card ID must be a valid ULID.');

        AttachMediaToCardData::fromInput(
            cardId: 'not-a-ulid',
            mediaAssetId: strtolower((string) Str::ulid()),
        );
    }

    public function test_it_rejects_invalid_media_asset_ulid(): void
    {
        $this->expectException(CannotAttachMediaToCard::class);
        $this->expectExceptionMessage('Media asset ID must be a valid ULID.');

        AttachMediaToCardData::fromInput(
            cardId: strtolower((string) Str::ulid()),
            mediaAssetId: 'not-a-ulid',
        );
    }
}
