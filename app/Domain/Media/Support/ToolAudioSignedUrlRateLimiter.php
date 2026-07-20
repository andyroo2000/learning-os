<?php

namespace App\Domain\Media\Support;

use App\Support\RateLimiting\RateLimitKey;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ToolAudioSignedUrlRateLimiter
{
    public const NAME = 'tool-audio-signed-url';

    public function __construct(
        private readonly StaticMediaSettings $settings,
    ) {}

    public function limit(Request $request): Limit
    {
        return (new Limit(
            '',
            $this->settings->toolAudioRateLimitMaxRequests(),
            $this->settings->toolAudioRateLimitWindowSeconds(),
        ))
            ->by(self::keyFor($request->ip()))
            ->response(
                fn (Request $request, array $headers): JsonResponse => response()->json([
                    'error' => 'Too many signed-url requests. Please retry shortly.',
                ], 429, $headers),
            );
    }

    public static function keyFor(?string $ip): string
    {
        return RateLimitKey::scopedUserOrNetwork(self::NAME, null, $ip);
    }
}
