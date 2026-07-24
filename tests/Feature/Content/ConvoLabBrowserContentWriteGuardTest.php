<?php

namespace Tests\Feature\Content;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConvoLabBrowserContentWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private const DEMO_MESSAGE = "You're exploring in demo mode, so content creation is disabled. "
        ."Thanks for checking out the app! If you'd like full access, please contact the admin.";

    private const FRONTEND_ORIGIN = 'https://convo-lab.test';

    private const VERIFICATION_MESSAGE = 'Please verify your email address before generating content. '
        .'Check your inbox for the verification email.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', ['convo-lab.test']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('verifiedGenerationEndpointProvider')]
    public function test_unverified_browser_users_cannot_use_generation_endpoints(
        string $method,
        string $uri,
        array $payload,
        int $_allowedStatus,
    ): void {
        [$user] = $this->projectedUser('user', verified: false);

        $this->callJsonAsBrowser($user, $method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', self::VERIFICATION_MESSAGE);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('verifiedGenerationEndpointProvider')]
    public function test_unverified_admin_browser_users_retain_generation_access(
        string $method,
        string $uri,
        array $payload,
        int $allowedStatus,
    ): void {
        [$admin] = $this->projectedUser('admin', verified: false);

        $this->callJsonAsBrowser($admin, $method, $uri, $payload)
            ->assertStatus($allowedStatus);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('verifiedGenerationEndpointProvider')]
    public function test_verified_demo_browser_users_cannot_use_generation_endpoints(
        string $method,
        string $uri,
        array $payload,
        int $_allowedStatus,
    ): void {
        [$demo] = $this->projectedUser('demo');

        $this->callJsonAsBrowser($demo, $method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', self::DEMO_MESSAGE);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('verifiedGenerationEndpointProvider')]
    public function test_verification_error_precedes_demo_error_for_unverified_demo_users(
        string $method,
        string $uri,
        array $payload,
        int $_allowedStatus,
    ): void {
        [$demo] = $this->projectedUser('demo', verified: false);

        $this->callJsonAsBrowser($demo, $method, $uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('message', self::VERIFICATION_MESSAGE);
    }

    public function test_course_retry_blocks_demo_users(): void
    {
        $courseId = (string) Str::uuid();
        [$demo] = $this->projectedUser('demo');

        $this->callJsonAsBrowser($demo, 'POST', "/api/convolab/courses/{$courseId}/retry")
            ->assertForbidden()
            ->assertJsonPath('message', self::DEMO_MESSAGE);
    }

    public function test_course_retry_does_not_require_email_verification(): void
    {
        $courseId = (string) Str::uuid();
        [$unverified] = $this->projectedUser('user', verified: false);

        $this->callJsonAsBrowser($unverified, 'POST', "/api/convolab/courses/{$courseId}/retry")
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('legacyUnguardedEndpointProvider')]
    public function test_legacy_unguarded_operations_remain_available_to_unverified_and_demo_users(
        string $method,
        string $uri,
        array $payload,
        string $role,
        bool $verified,
        int $allowedStatus,
    ): void {
        [$user] = $this->projectedUser($role, $verified);

        $this->callJsonAsBrowser($user, $method, $uri, $payload)
            ->assertStatus($allowedStatus);
    }

    /** @return array<string, array{string, string, array<string, mixed>, int}> */
    public static function verifiedGenerationEndpointProvider(): array
    {
        $id = '5d213e07-c2c8-481c-8bc5-1abe1cb75d6e';

        return [
            'dialogue generation' => ['POST', '/api/convolab/dialogue/generate', [], 422],
            'script creation' => ['POST', '/api/convolab/scripts', [], 422],
            'script annotation' => ['POST', "/api/convolab/scripts/{$id}/annotate", [], 404],
            'script segment update' => ['PATCH', "/api/convolab/scripts/{$id}/segments", [], 422],
            'script render' => ['POST', "/api/convolab/scripts/{$id}/render", [], 404],
            'script images' => ['POST', "/api/convolab/scripts/{$id}/images", [], 404],
            'course generation' => ['POST', "/api/convolab/courses/{$id}/generate", [], 404],
        ];
    }

    /** @return array<string, array{string, string, array<string, mixed>, string, bool, int}> */
    public static function legacyUnguardedEndpointProvider(): array
    {
        $id = '23c2133b-2a96-4586-851a-d395c2b09807';

        return [
            'unverified course reset' => ['POST', "/api/convolab/courses/{$id}/reset", [], 'user', false, 404],
            'demo course reset' => ['POST', "/api/convolab/courses/{$id}/reset", [], 'demo', true, 404],
            'unverified image generation' => ['POST', '/api/convolab/images/generate', [], 'user', false, 422],
            'demo image generation' => ['POST', '/api/convolab/images/generate', [], 'demo', true, 422],
            'unverified single-speed audio' => ['POST', '/api/convolab/audio/generate', [], 'user', false, 422],
            'demo single-speed audio' => ['POST', '/api/convolab/audio/generate', [], 'demo', true, 422],
            'unverified all-speeds audio' => [
                'POST',
                '/api/convolab/audio/generate-all-speeds',
                [],
                'user',
                false,
                422,
            ],
            'demo all-speeds audio' => [
                'POST',
                '/api/convolab/audio/generate-all-speeds',
                [],
                'demo',
                true,
                422,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function callJsonAsBrowser(
        User $user,
        string $method,
        string $uri,
        array $payload = [],
    ): TestResponse {
        return $this->actingAs($user, 'web')
            ->withHeader('Origin', self::FRONTEND_ORIGIN)
            ->withHeader('Referer', self::FRONTEND_ORIGIN.'/')
            ->json($method, $uri, $payload);
    }

    /** @return array{User, string} */
    private function projectedUser(string $role, bool $verified = true): array
    {
        $convoLabUserId = (string) Str::uuid();
        $user = User::factory()
            ->when(! $verified, fn ($factory) => $factory->unverified())
            ->create(['email' => $convoLabUserId.'@example.com']);

        $this->convoLabProjectionFor($user, $convoLabUserId, [
            'role' => $role,
            'email_verified' => $verified,
            'email_verified_at' => $verified ? now() : null,
        ]);

        return [$user->refresh(), $convoLabUserId];
    }
}
