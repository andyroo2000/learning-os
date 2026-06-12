<?php

namespace App\Domain\Flashcards\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class DeckRateLimiter
{
    public const CREATE_NAME = 'deck-create';

    public const UPDATE_NAME = 'deck-update';

    public const DELETE_NAME = 'deck-delete';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function forCreate(): self
    {
        // Deck creation is retryable for offline clients; 60/min leaves room for backlog replay.
        return new self(self::CREATE_NAME, 60);
    }

    public static function forUpdate(): self
    {
        // Deck metadata edits are manual but retryable; 60/min keeps updates separate from creates.
        return new self(self::UPDATE_NAME, 60);
    }

    public static function forDelete(): self
    {
        // Deck deletes are low-frequency destructive gestures; 30/min still tolerates retry loops.
        return new self(self::DELETE_NAME, 30);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    public static function keyFor(string $limiterName, mixed $userId, ?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork($limiterName, $userId, $ip);
    }

    private function key(Request $request): string
    {
        return self::keyFor(
            $this->name,
            $request->user()?->getAuthIdentifier(),
            $request->ip(),
        );
    }
}
