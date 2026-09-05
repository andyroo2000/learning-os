<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Support\ContentAudioScriptInput;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Content\BuildsContentAudioScripts;
use Tests\TestCase;

class ContentAudioScriptAccessApiTest extends TestCase
{
    use BuildsContentAudioScripts;
    use RefreshDatabase;

    public function test_routes_require_a_first_party_browser_session(): void
    {
        $episodeId = (string) Str::uuid();
        $mediaId = (string) Str::uuid();

        $this->assertSame(401, $this->postJson('/api/convolab/scripts', [])->status(), 'create');
        $this->assertSame(401, $this->postJson("/api/convolab/scripts/{$episodeId}/annotate")->status(), 'annotate');
        $this->assertSame(401, $this->patchJson("/api/convolab/scripts/{$episodeId}/segments", [])->status(), 'segments');
        $this->assertSame(401, $this->getJson("/api/convolab/scripts/{$episodeId}/status")->status(), 'status');
        $this->getJson("/api/convolab/scripts/media/{$mediaId}")->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('mobile', ['content:write'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/convolab/scripts', [
                'sourceText' => '日本語です。',
                'voiceId' => ContentAudioScriptInput::DEFAULT_VOICE_ID,
            ])
            ->assertForbidden();
        $this->withToken($token)
            ->getJson("/api/convolab/scripts/{$episodeId}/status")
            ->assertForbidden();
    }

    public function test_status_is_owner_scoped_and_orders_segments_and_renders(): void
    {
        $user = User::factory()->create();
        [$episode, $script] = $this->script($user);
        $second = $this->segment($script, ['sort_order' => 1, 'text' => '二番です。']);
        $first = $this->segment($script, ['sort_order' => 0, 'text' => '一番です。']);
        $this->render($script, ['speed' => 'normal', 'numeric_speed' => 1.0]);
        $slow = $this->render($script, ['speed' => 'slow', 'numeric_speed' => 0.7]);
        $this->authenticateWrite($user);

        $this->getJson('/api/convolab/scripts/'.strtoupper($episode->id).'/status')
            ->assertOk()
            ->assertJsonPath('segments.0.id', $first->id)
            ->assertJsonPath('segments.1.id', $second->id)
            ->assertJsonPath('renders.0.id', $slow->id);

        $otherUser = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($otherUser)
            ->getJson("/api/convolab/scripts/{$episode->id}/status")
            ->assertNotFound();
    }

    public function test_private_media_is_owner_scoped_path_allowlisted_and_security_hardened(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        [, $script] = $this->script($user);
        $media = $this->media($user);
        $this->segment($script, ['image_media_id' => $media->id]);
        Storage::disk('media')->put($media->storage_path, 'image-bytes');
        $this->authenticateWrite($user);

        $this->get('/api/convolab/scripts/media/'.strtoupper($media->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Content-Disposition', 'inline; filename="scene.webp"')
            ->assertHeader('Cache-Control', 'immutable, max-age=15552000, private')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'")
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $otherUser = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->asConvoLabBrowser($otherUser)
            ->get("/api/convolab/scripts/media/{$media->id}")
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->authenticateWrite($user);
        foreach ([
            ['storage_path' => 'study-media/../secret.webp'],
            ['storage_path' => '/study-media/user/scene.webp'],
            ['storage_path' => 'study-media/user/scene.webp', 'content_type' => 'image/svg+xml'],
            ['storage_path' => 'study-media/user/missing.webp'],
        ] as $attributes) {
            $blocked = $this->media($user, $attributes);
            $this->segment($script, [
                'sort_order' => ContentAudioScriptSegment::query()->where('script_id', $script->id)->count(),
                'image_media_id' => $blocked->id,
            ]);
            $this->get("/api/convolab/scripts/media/{$blocked->id}")->assertNotFound();
        }
    }

    public function test_script_routes_use_the_expected_shared_rate_limit_buckets(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->keyBy(fn ($route) => implode('|', $route->methods()).' '.$route->uri());

        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME,
            $routes->get('POST api/convolab/scripts')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME,
            $routes->get('POST api/convolab/scripts/{episodeId}/annotate')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::UPDATE_NAME,
            $routes->get('PATCH api/convolab/scripts/{episodeId}/segments')->gatherMiddleware(),
        );
        foreach ([
            'POST api/convolab/scripts/{episodeId}/render',
            'POST api/convolab/scripts/{episodeId}/images',
        ] as $route) {
            $this->assertContains(
                'throttle:'.ContentAudioScriptRateLimiter::GENERATION_NAME,
                $routes->get($route)->gatherMiddleware(),
            );
        }
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME,
            $routes->get('GET|HEAD api/convolab/scripts/media/{mediaId}')->gatherMiddleware(),
        );
        $this->assertContains(
            'throttle:'.ContentAudioScriptRateLimiter::MEDIA_READ_NAME,
            $routes->get('GET|HEAD api/convolab/scripts/{episodeId}/audio/{renderId}')->gatherMiddleware(),
        );

        $request = Request::create('/api/convolab/scripts', 'POST', server: ['REMOTE_ADDR' => '203.0.113.4']);
        $request->setUserResolver(
            fn (): User => User::factory()->make([
                'convolab_id' => strtoupper($this->convoLabUserId),
            ]),
        );
        foreach ([
            [ContentAudioScriptRateLimiter::generation($request), ContentAudioScriptRateLimiter::GENERATION_NAME, 10],
            [ContentAudioScriptRateLimiter::update($request), ContentAudioScriptRateLimiter::UPDATE_NAME, 120],
            [ContentAudioScriptRateLimiter::mediaRead($request), ContentAudioScriptRateLimiter::MEDIA_READ_NAME, 240],
        ] as [$limit, $name, $attempts]) {
            $this->assertSame($attempts, $limit->maxAttempts);
            $this->assertSame("{$name}:user:{$this->convoLabUserId}", $limit->key);
        }

        $fallback = Request::create('/api/convolab/scripts', 'POST', server: ['REMOTE_ADDR' => '203.0.113.4']);
        $fallback->setUserResolver(fn () => new class
        {
            public function getAuthIdentifier(): int
            {
                return 42;
            }
        });
        $this->assertSame(
            ContentAudioScriptRateLimiter::GENERATION_NAME.':user:42',
            ContentAudioScriptRateLimiter::generation($fallback)->key,
        );

        $anonymous = Request::create('/api/convolab/scripts', 'POST');
        $this->assertSame(
            ContentAudioScriptRateLimiter::GENERATION_NAME.':anon:127.0.0.1',
            ContentAudioScriptRateLimiter::generation($anonymous)->key,
        );
    }
}
