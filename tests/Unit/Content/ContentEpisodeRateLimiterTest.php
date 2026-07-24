<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContentEpisodeRateLimiterTest extends TestCase
{
    #[DataProvider('limiterProvider')]
    public function test_limiters_use_the_effective_convolab_user_uuid(
        ContentEpisodeRateLimiter $limiter,
        int $expectedAttempts,
        string $expectedScope,
    ): void {
        $request = $this->request(' C358732A-2CD0-4B18-9CCE-C474297863F9 ');

        $limit = $limiter->limit($request);

        $this->assertSame($expectedAttempts, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
        $this->assertSame(
            $expectedScope.':user:c358732a-2cd0-4b18-9cce-c474297863f9',
            $limit->key,
        );
    }

    public function test_missing_canonical_identity_falls_back_to_the_authenticated_account(): void
    {
        $this->assertSame(
            'content-episode-create:user:42',
            ContentEpisodeRateLimiter::create()->limit($this->request(null))->key,
        );
    }

    public static function limiterProvider(): array
    {
        return [
            'create' => [ContentEpisodeRateLimiter::create(), 60, ContentEpisodeRateLimiter::CREATE_NAME],
            'update' => [ContentEpisodeRateLimiter::update(), 60, ContentEpisodeRateLimiter::UPDATE_NAME],
            'delete' => [ContentEpisodeRateLimiter::delete(), 30, ContentEpisodeRateLimiter::DELETE_NAME],
        ];
    }

    private function request(?string $convoLabUserId): Request
    {
        $request = Request::create(
            '/api/convolab/episodes',
            'POST',
            server: ['REMOTE_ADDR' => '127.0.0.1'],
        );
        $user = new User;
        $user->setAttribute('id', 42);
        $user->setAttribute('convolab_id', $convoLabUserId);
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }
}
