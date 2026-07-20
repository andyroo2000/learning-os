<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Support\StaticMediaSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaticMediaSettingsTest extends TestCase
{
    private StaticMediaSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new StaticMediaSettings;
        config([
            'static_media.gcs.bucket' => null,
            'static_media.avatars.gcs_root' => 'avatars',
            'static_media.avatars.signed_urls_enabled' => null,
            'static_media.avatars.signed_url_ttl_seconds' => 43_200,
            'static_media.tool_audio.gcs_root' => 'tools-audio',
            'static_media.tool_audio.signed_urls_enabled' => null,
            'static_media.tool_audio.signed_url_ttl_seconds' => 43_200,
            'static_media.tool_audio.rate_limit_window_ms' => 60_000,
            'static_media.tool_audio.rate_limit_max_requests' => 120,
        ]);
    }

    public function test_signing_defaults_to_bucket_presence_and_honors_explicit_values(): void
    {
        $this->assertFalse($this->settings->avatarSigningEnabled());
        $this->assertFalse($this->settings->toolAudioSigningEnabled());

        config(['static_media.gcs.bucket' => 'convolab-storage']);
        $this->assertTrue($this->settings->avatarSigningEnabled());
        $this->assertTrue($this->settings->toolAudioSigningEnabled());

        config([
            'static_media.avatars.signed_urls_enabled' => 'false',
            'static_media.tool_audio.signed_urls_enabled' => 'true',
        ]);
        $this->assertFalse($this->settings->avatarSigningEnabled());
        $this->assertTrue($this->settings->toolAudioSigningEnabled());
    }

    #[DataProvider('ttlCases')]
    public function test_ttl_values_preserve_defaults_and_clamp_to_supported_bounds(
        mixed $configured,
        int $expected,
    ): void {
        config([
            'static_media.avatars.signed_url_ttl_seconds' => $configured,
            'static_media.tool_audio.signed_url_ttl_seconds' => $configured,
        ]);

        $this->assertSame($expected, $this->settings->avatarTtlSeconds());
        $this->assertSame($expected, $this->settings->toolAudioTtlSeconds());
    }

    #[DataProvider('rateLimitCases')]
    public function test_rate_limit_values_preserve_defaults_and_clamp_supported_bounds(
        mixed $windowMilliseconds,
        mixed $maxRequests,
        int $expectedWindowSeconds,
        int $expectedMaxRequests,
    ): void {
        config([
            'static_media.tool_audio.rate_limit_window_ms' => $windowMilliseconds,
            'static_media.tool_audio.rate_limit_max_requests' => $maxRequests,
        ]);

        $this->assertSame(
            $expectedWindowSeconds,
            $this->settings->toolAudioRateLimitWindowSeconds(),
        );
        $this->assertSame(
            $expectedMaxRequests,
            $this->settings->toolAudioRateLimitMaxRequests(),
        );
    }

    public function test_object_paths_normalize_operator_roots_and_public_urls(): void
    {
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.avatars.gcs_root' => '/public avatars/',
            'static_media.tool_audio.gcs_root' => '/tool audio/',
        ]);

        $this->assertSame(
            'public avatars/voices/ja-shohei.jpg',
            $this->settings->avatarObjectPath('voices/ja-shohei.jpg'),
        );
        $this->assertSame(
            'tool audio/japanese-time/minute/44.mp3',
            $this->settings->toolAudioObjectPath('/tools-audio/japanese-time/minute/44.mp3'),
        );
        $this->assertSame(
            'https://storage.googleapis.com/convolab-storage/public%20avatars/voices/ja-shohei.jpg',
            $this->settings->publicObjectUrl('public avatars/voices/ja-shohei.jpg'),
        );
    }

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function ttlCases(): iterable
    {
        yield 'default on invalid' => ['invalid', StaticMediaSettings::DEFAULT_TTL_SECONDS];
        yield 'minimum accepted' => [StaticMediaSettings::MIN_TTL_SECONDS, 300];
        yield 'below minimum clamped' => [1, 300];
        yield 'maximum accepted' => [StaticMediaSettings::MAX_TTL_SECONDS, 86_400];
        yield 'above maximum clamped' => [100_000, 86_400];
    }

    /**
     * @return iterable<string, array{mixed, mixed, int, int}>
     */
    public static function rateLimitCases(): iterable
    {
        yield 'defaults on invalid' => [
            'invalid',
            'invalid',
            60,
            StaticMediaSettings::DEFAULT_RATE_LIMIT_MAX_REQUESTS,
        ];
        yield 'minimums' => [1, 0, 1, 1];
        yield 'ceil milliseconds' => [1501, 1, 2, 1];
        yield 'maximums' => [9_999_999, 9_999, 3600, 5000];
    }
}
