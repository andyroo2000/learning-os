<?php

namespace Tests\Feature\Media;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Exceptions\MediaOwnershipException;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\CardMediaOwnership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardMediaOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_shared_owner_id(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $mediaAsset = $this->mediaAssetFor($user);

        $this->assertSame($user->id, CardMediaOwnership::ownerUserIdFor($card, $mediaAsset));
    }

    public function test_it_resolves_owners_for_soft_deleted_card_decks(): void
    {
        $user = User::factory()->create();
        $card = $this->cardFor($user);
        $mediaAsset = $this->mediaAssetFor($user);

        $card->deck()->firstOrFail()->delete();

        $this->assertSame($user->id, CardMediaOwnership::ownerUserIdFor($card, $mediaAsset));
    }

    public function test_it_rejects_different_owners(): void
    {
        $card = $this->cardFor(User::factory()->create());
        $mediaAsset = MediaAsset::factory()
            ->for(User::factory()->create())
            ->create();

        $this->expectException(MediaOwnershipException::class);
        $this->expectExceptionMessage(
            "Card {$card->id} owner {$card->ownerUserId()} and media asset {$mediaAsset->id} owner {$mediaAsset->user_id} differ.",
        );

        CardMediaOwnership::ownerUserIdFor($card, $mediaAsset);
    }

    public function test_it_rejects_unresolvable_card_owners(): void
    {
        $card = new Card([
            'deck_id' => strtolower((string) str()->ulid()),
            'front_text' => 'front',
            'back_text' => 'back',
        ]);
        $mediaAsset = $this->mediaAssetFor(User::factory()->create());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Card deck owner could not be resolved.');

        CardMediaOwnership::ownerUserIdFor($card, $mediaAsset);
    }

    #[DataProvider('unresolvableMediaOwnerProvider')]
    public function test_it_rejects_unresolvable_media_asset_owners(mixed $userId): void
    {
        $card = $this->cardFor(User::factory()->create());
        $mediaAsset = new MediaAsset;
        $mediaAsset->setRawAttributes(['user_id' => $userId], sync: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Media asset owner could not be resolved.');

        CardMediaOwnership::ownerUserIdFor($card, $mediaAsset);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unresolvableMediaOwnerProvider(): array
    {
        return [
            'missing' => [null],
            'zero' => [0],
            'string zero' => ['0'],
            'negative' => [-1],
            'string negative' => ['-1'],
            'malformed numeric string' => ['3abc'],
            'empty string' => [''],
        ];
    }
}
