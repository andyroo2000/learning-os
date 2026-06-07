<?php

namespace Tests\Unit\Flashcards;

use App\Domain\Flashcards\Support\DeckRateLimiter;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeckRateLimiterTest extends TestCase
{
    #[DataProvider('keyProvider')]
    public function test_it_builds_stable_user_and_network_keys(string $limiterName, mixed $userId, ?string $ip, string $expected): void
    {
        $this->assertSame($expected, DeckRateLimiter::keyFor($limiterName, $userId, $ip));
    }

    #[DataProvider('defaultLimiterProvider')]
    public function test_it_uses_expected_attempts_per_minute_by_default(
        DeckRateLimiter $limiter,
        string $method,
        string $uri,
        int $expectedAttempts,
        string $expectedKey,
    ): void {
        $request = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $request->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });

        $limit = $limiter->limit($request);

        $this->assertSame($expectedAttempts, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame($expectedKey, $limit->key);
    }

    /**
     * @return array<string, array{string, mixed, string|null, string}>
     */
    public static function keyProvider(): array
    {
        return [
            'user id ignores localhost' => [DeckRateLimiter::CREATE_NAME, 42, '127.0.0.1', 'deck-create:user:42'],
            'user id ignores public ip' => [DeckRateLimiter::CREATE_NAME, 42, '192.0.2.10', 'deck-create:user:42'],
            'anonymous null ip' => [DeckRateLimiter::CREATE_NAME, null, null, 'deck-create:anon:unknown-ip'],
            'anonymous empty ip' => [DeckRateLimiter::CREATE_NAME, null, '', 'deck-create:anon:unknown-ip'],
            'false user id falls back to network key' => [DeckRateLimiter::CREATE_NAME, false, '127.0.0.1', 'deck-create:anon:127.0.0.1'],
            'anonymous localhost' => [DeckRateLimiter::CREATE_NAME, null, '127.0.0.1', 'deck-create:anon:127.0.0.1'],
            'anonymous public ip' => [DeckRateLimiter::CREATE_NAME, null, '192.0.2.10', 'deck-create:anon:192.0.2.10'],
            'string user id' => [DeckRateLimiter::CREATE_NAME, 'user-1', '', 'deck-create:user:user-1'],
            'sentinel-looking string user id' => [DeckRateLimiter::CREATE_NAME, 'str-id', '', 'deck-create:user:str-id'],
        ];
    }

    /**
     * @return array<string, array{DeckRateLimiter, string, string, int, string}>
     */
    public static function defaultLimiterProvider(): array
    {
        return [
            'create' => [DeckRateLimiter::create(), 'POST', '/api/decks', 60, 'deck-create:user:42'],
            'update' => [DeckRateLimiter::update(), 'PUT', '/api/decks/01HWZ1KCE7000000000000000', 60, 'deck-update:user:42'],
            'delete' => [DeckRateLimiter::delete(), 'DELETE', '/api/decks/01HWZ1KCE7000000000000000', 30, 'deck-delete:user:42'],
        ];
    }
}
