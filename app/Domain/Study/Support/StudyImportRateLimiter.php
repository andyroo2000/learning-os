<?php

namespace App\Domain\Study\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

final class StudyImportRateLimiter
{
    public const CREATE_NAME = 'study-import-create';

    public const UPLOAD_NAME = 'study-import-upload';

    public const COMPLETE_NAME = 'study-import-complete';

    public const CANCEL_NAME = 'study-import-cancel';

    private function __construct(
        private readonly string $name,
        private readonly int $perMinute,
    ) {}

    public static function forCreateSession(): self
    {
        // Only one active import is allowed, so 10/min leaves retry room without encouraging churn.
        return new self(self::CREATE_NAME, 10);
    }

    public static function forUpload(): self
    {
        // Upload retries can be large but are byte-capped by validation; keep them out of lifecycle buckets.
        return new self(self::UPLOAD_NAME, 30);
    }

    public static function forComplete(): self
    {
        // Completion may be retried while clients wait for queueing, separate from raw upload attempts.
        return new self(self::COMPLETE_NAME, 30);
    }

    public static function forCancel(): self
    {
        // Cancel is a manual retry-safe lifecycle write; keep it independent from uploads.
        return new self(self::CANCEL_NAME, 30);
    }

    public function limit(Request $request): Limit
    {
        return Limit::perMinute($this->perMinute)->by($this->key($request));
    }

    /**
     * @internal Exposed for focused limiter tests; route code should call limit().
     */
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
