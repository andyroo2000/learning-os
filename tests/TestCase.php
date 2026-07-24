<?php

namespace Tests;

use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use UnexpectedValueException;

abstract class TestCase extends BaseTestCase
{
    // Subclasses overriding tearDown() must call parent::tearDown() for shared cleanup.
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Avoid leaking frozen clocks into later tests when teardown fails.
            Carbon::setTestNow();
        }
    }

    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();

        Sanctum::actingAs($user);

        return $user;
    }

    protected function asConvoLabBrowser(
        User $user,
        string $role = 'user',
        ?string $convoLabUserId = null,
    ): static {
        $convoLabUserId ??= is_string($user->convolab_id) && $user->convolab_id !== ''
            ? $user->convolab_id
            : (string) Str::uuid();
        $convoLabUserId = strtolower(trim($convoLabUserId));

        $user->forceFill([
            'convolab_id' => $convoLabUserId,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if (! AdminUserProjection::query()->whereKey($convoLabUserId)->exists()) {
            $this->convoLabProjectionFor($user, $convoLabUserId, [
                'role' => $role,
                'email_verified' => $user->email_verified_at !== null,
                'email_verified_at' => $user->email_verified_at,
            ]);
        }

        config()->set('sanctum.stateful', ['convo-lab.test']);

        return $this->actingAs($user, 'web')
            ->withHeader('Origin', 'https://convo-lab.test')
            ->withHeader('Referer', 'https://convo-lab.test/');
    }

    protected function asConvoLabAdminBrowser(
        ?User $user = null,
        ?string $convoLabUserId = null,
    ): static {
        $normalizedId = is_string($convoLabUserId)
            ? strtolower(trim($convoLabUserId))
            : null;
        $user ??= $normalizedId === null
            ? AdminUserProjection::query()->whereNotNull('user_id')->first()?->user
                ?? User::factory()->create()
            : User::query()->where('convolab_id', $normalizedId)->first()
                ?? User::factory()->create();

        $this->asConvoLabBrowser(
            $user,
            role: 'admin',
            convoLabUserId: $normalizedId,
        );

        AdminUserProjection::query()
            ->where('user_id', $user->id)
            ->update(['role' => 'admin']);

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    protected function convoLabProjectionFor(
        User $user,
        string $convoLabUserId,
        array $attributes = [],
    ): AdminUserProjection {
        $user->convolab_id = $convoLabUserId;
        $user->save();

        return AdminUserProjection::query()->forceCreate([
            'convolab_id' => $convoLabUserId,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now(),
            ...$attributes,
        ]);
    }

    protected function assertUrlQueryParameter(string $url, string $key, string $expected): void
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        $this->assertIsString($queryString, "URL has no query string: {$url}");

        parse_str($queryString, $query);

        $this->assertSame($expected, $query[$key] ?? null, "URL query parameter [{$key}] did not match: {$url}");
    }

    protected function assertJsonTimestamp(mixed $value): void
    {
        $this->assertNotNull($value);
        $this->assertIsString($value);
        // Carbon::toJSON() emits UTC timestamps with exactly six fractional digits.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $value);
    }

    protected function pathAndQueryFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            throw new UnexpectedValueException("URL has no path: {$url}");
        }

        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return $path;
        }

        return "{$path}?{$queryString}";
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
