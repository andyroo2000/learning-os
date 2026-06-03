<?php

namespace Tests;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    protected function assertUrlQueryParameter(string $url, string $key, string $expected): void
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        $this->assertIsString($queryString, "URL has no query string: {$url}");

        parse_str($queryString, $query);

        $this->assertSame($expected, $query[$key] ?? null, "URL query parameter [{$key}] did not match: {$url}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function deckFor(User $user, array $attributes = []): Deck
    {
        return Deck::factory()
            ->for($user)
            ->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cardFor(User $user, array $attributes = []): Card
    {
        return Card::factory()
            ->for($this->deckFor($user))
            ->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function mediaAssetFor(User $user, array $attributes = []): MediaAsset
    {
        return MediaAsset::factory()
            ->for($user)
            ->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function mediaAssetForCardOwner(Card $card, array $attributes = []): MediaAsset
    {
        if ($card->relationLoaded('deck') && $card->deck !== null) {
            $deck = $card->deck;
            $deck->loadMissing('user');
        } else {
            $deck = $card->deck()->withTrashed()->with('user')->firstOrFail();
        }

        return $this->mediaAssetFor($deck->user, $attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cardReviewEventFor(User $user, array $attributes = []): CardReviewEvent
    {
        return CardReviewEvent::factory()
            ->for($this->cardFor($user))
            ->create($attributes);
    }
}
