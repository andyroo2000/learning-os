<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use DateTimeInterface;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StaticMediaApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'static_media.gcs.bucket' => null,
            'static_media.avatars.gcs_root' => 'avatars',
            'static_media.avatars.signed_urls_enabled' => false,
            'static_media.avatars.signed_url_ttl_seconds' => 43_200,
            'static_media.tool_audio.gcs_root' => 'tools-audio',
            'static_media.tool_audio.signed_urls_enabled' => false,
            'static_media.tool_audio.signed_url_ttl_seconds' => 43_200,
            'static_media.tool_audio.rate_limit_window_ms' => 60_000,
            'static_media.tool_audio.rate_limit_max_requests' => 120,
        ]);
    }

    public function test_avatar_redirects_to_a_signed_object_with_private_redirect_caching(): void
    {
        Carbon::setTestNow('2026-07-20 20:00:00 UTC');
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.avatars.signed_urls_enabled' => true,
        ]);
        $store = $this->mockStore();
        $store->shouldReceive('exists')
            ->once()
            ->with('avatars/voices/ja-shohei.jpg')
            ->andReturnTrue();
        $store->shouldReceive('signedReadUrl')
            ->once()
            ->withArgs(function (
                string $objectPath,
                DateTimeInterface $expiresAt,
                ?string $responseType,
            ): bool {
                return $objectPath === 'avatars/voices/ja-shohei.jpg'
                    && $expiresAt->getTimestamp() === Carbon::now()->addHours(12)->getTimestamp()
                    && $responseType === 'image/jpeg';
            })
            ->andReturn('https://signed.example/ja-shohei.jpg');

        try {
            $this->getJson('/api/avatars/voices/ja-shohei.jpg')
                ->assertStatus(302)
                ->assertRedirect('https://signed.example/ja-shohei.jpg')
                ->assertHeader('Cache-Control', 'max-age=300, private');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_avatar_rejects_unsafe_paths_before_accessing_storage(): void
    {
        $store = $this->mockStore();
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        $this->getJson('/api/avatars/voices/../../secret.jpg')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Avatar not found']);
    }

    public function test_avatar_returns_not_found_when_the_object_is_missing(): void
    {
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.avatars.signed_urls_enabled' => true,
        ]);
        $store = $this->mockStore();
        $store->shouldReceive('exists')
            ->once()
            ->with('avatars/voices/ja-missing.jpg')
            ->andReturnFalse();
        $store->shouldNotReceive('signedReadUrl');

        $this->getJson('/api/avatars/voices/ja-missing.jpg')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Avatar not found']);
    }

    public function test_avatar_falls_back_to_the_public_gcs_url_when_signing_fails(): void
    {
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.avatars.signed_urls_enabled' => true,
        ]);
        $store = $this->mockStore();
        $store->shouldReceive('exists')->once()->andReturnTrue();
        $store->shouldReceive('signedReadUrl')
            ->once()
            ->andThrow(new RuntimeException('missing signer'));

        $this->getJson('/api/avatars/voices/ja-shohei.jpg')
            ->assertStatus(302)
            ->assertRedirect(
                'https://storage.googleapis.com/convolab-storage/avatars/voices/ja-shohei.jpg',
            );
    }

    public function test_avatar_uses_the_local_static_path_when_signing_is_disabled(): void
    {
        $store = $this->mockStore();
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        $this->getJson('/api/avatars/ja-male-casual.jpg')
            ->assertStatus(302)
            ->assertRedirect('/avatars/ja-male-casual.jpg');
    }

    public function test_tool_audio_returns_the_passthrough_contract_when_signing_is_disabled(): void
    {
        Carbon::setTestNow('2026-07-20 20:00:00 UTC');
        $path = '/tools-audio/japanese-time/google-kento-professional/time/minute/44.mp3';
        $store = $this->mockStore();
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        try {
            $this->postJson('/api/tools-audio/signed-urls', ['paths' => [$path]])
                ->assertOk()
                ->assertExactJson([
                    'mode' => 'passthrough',
                    'ttlSeconds' => 43_200,
                    'urls' => [
                        $path => [
                            'url' => $path,
                            'expiresAt' => '2026-07-21T08:00:00.000000Z',
                        ],
                    ],
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tool_audio_normalizes_absolute_urls_and_deduplicates_paths_without_global_trimming(): void
    {
        Carbon::setTestNow('2026-07-20 20:00:00 UTC');
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.tool_audio.signed_urls_enabled' => true,
        ]);
        $path = '/tools-audio/japanese-time/google-kento-professional/time/minute/44.mp3';
        $store = $this->mockStore();
        $store->shouldReceive('exists')
            ->once()
            ->with('tools-audio/japanese-time/google-kento-professional/time/minute/44.mp3')
            ->andReturnTrue();
        $store->shouldReceive('signedReadUrl')
            ->once()
            ->withArgs(fn (
                string $objectPath,
                DateTimeInterface $expiresAt,
                ?string $responseType,
            ): bool => $objectPath === ltrim($path, '/')
                && $expiresAt->getTimestamp() === Carbon::now()->addHours(12)->getTimestamp()
                && $responseType === null)
            ->andReturn('https://signed.example/minute-44.mp3');

        try {
            $this->withoutMiddleware(TrimStrings::class)
                ->postJson('/api/tools-audio/signed-urls', [
                    'paths' => [
                        " https://cdn.example{$path} ",
                        $path,
                    ],
                ])
                ->assertOk()
                ->assertExactJson([
                    'mode' => 'signed',
                    'ttlSeconds' => 43_200,
                    'urls' => [
                        $path => [
                            'url' => 'https://signed.example/minute-44.mp3',
                            'expiresAt' => '2026-07-21T08:00:00.000000Z',
                        ],
                    ],
                ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tool_audio_falls_back_per_path_when_an_object_is_missing_or_signing_fails(): void
    {
        config([
            'static_media.gcs.bucket' => 'convolab-storage',
            'static_media.tool_audio.signed_urls_enabled' => true,
        ]);
        $missing = '/tools-audio/japanese-time/missing.mp3';
        $failed = '/tools-audio/japanese-time/failed.mp3';
        $store = $this->mockStore();
        $store->shouldReceive('exists')
            ->once()
            ->with('tools-audio/japanese-time/missing.mp3')
            ->andReturnFalse();
        $store->shouldReceive('exists')
            ->once()
            ->with('tools-audio/japanese-time/failed.mp3')
            ->andReturnTrue();
        $store->shouldReceive('signedReadUrl')
            ->once()
            ->andThrow(new RuntimeException('signer unavailable'));

        $response = $this->postJson(
            '/api/tools-audio/signed-urls',
            ['paths' => [$missing, $failed]],
        );

        $response->assertOk()->assertJsonPath('mode', 'signed');
        $this->assertSame($missing, $response->json('urls')[$missing]['url']);
        $this->assertSame($failed, $response->json('urls')[$failed]['url']);
    }

    #[DataProvider('invalidToolAudioPayloads')]
    public function test_tool_audio_rejects_invalid_payloads_with_the_legacy_error_contract(
        array $payload,
    ): void {
        $store = $this->mockStore();
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        $this->postJson('/api/tools-audio/signed-urls', $payload)
            ->assertStatus(400)
            ->assertExactJson([
                'error' => 'paths must be an array of 1-60 valid /tools-audio/*.mp3 values',
            ]);
    }

    public function test_tool_audio_uses_a_shared_network_rate_limit_with_retry_headers(): void
    {
        config([
            'static_media.tool_audio.rate_limit_max_requests' => 2,
            'static_media.tool_audio.rate_limit_window_ms' => 60_000,
        ]);
        $path = '/tools-audio/japanese-time/minute/44.mp3';
        $store = $this->mockStore();
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        $request = fn () => $this
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->postJson('/api/tools-audio/signed-urls', ['paths' => [$path]]);

        $request()->assertOk();
        $request()->assertOk();
        $request()
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath(
                'error',
                'Too many signed-url requests. Please retry shortly.',
            );

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.4'])
            ->postJson('/api/tools-audio/signed-urls', ['paths' => [$path]])
            ->assertOk();
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidToolAudioPayloads(): iterable
    {
        yield 'missing paths' => [[]];
        yield 'scalar paths' => [['paths' => 'not-an-array']];
        yield 'empty paths' => [['paths' => []]];
        yield 'non-string path' => [['paths' => [42]]];
        yield 'traversal' => [['paths' => ['/tools-audio/../../secret.mp3']]];
        yield 'query string' => [['paths' => ['/tools-audio/valid.mp3?token=secret']]];
        yield 'wrong extension' => [['paths' => ['/tools-audio/valid.wav']]];
        yield 'overlong path' => [['paths' => ['/tools-audio/'.str_repeat('a', 300).'.mp3']]];
        yield 'too many paths' => [[
            'paths' => array_map(
                fn (int $index): string => "/tools-audio/minute/{$index}.mp3",
                range(0, 60),
            ),
        ]];
    }

    private function mockStore(): StaticMediaObjectStore&MockInterface
    {
        $store = Mockery::mock(StaticMediaObjectStore::class);
        $this->app->instance(StaticMediaObjectStore::class, $store);

        return $store;
    }
}
