<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Support\StaticMediaSettings;
use App\Domain\Media\Support\ToolAudioSignedUrlRateLimiter;
use Illuminate\Http\Request;
use Tests\TestCase;

class ToolAudioSignedUrlRateLimiterTest extends TestCase
{
    public function test_it_uses_the_configured_defaults_and_operation_scoped_network_key(): void
    {
        config([
            'static_media.tool_audio.rate_limit_window_ms' => 60_000,
            'static_media.tool_audio.rate_limit_max_requests' => 120,
        ]);
        $request = Request::create(
            '/api/tools-audio/signed-urls',
            'POST',
            server: ['REMOTE_ADDR' => '203.0.113.60'],
        );

        $limit = (new ToolAudioSignedUrlRateLimiter(new StaticMediaSettings))->limit($request);

        $this->assertSame(120, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame('tool-audio-signed-url:anon:203.0.113.60', $limit->key);
    }

    public function test_it_fails_closed_to_one_shared_unknown_network_bucket(): void
    {
        $this->assertSame(
            'tool-audio-signed-url:anon:unknown-ip',
            ToolAudioSignedUrlRateLimiter::keyFor(null),
        );
        $this->assertSame(
            'tool-audio-signed-url:anon:unknown-ip',
            ToolAudioSignedUrlRateLimiter::keyFor(''),
        );
    }
}
