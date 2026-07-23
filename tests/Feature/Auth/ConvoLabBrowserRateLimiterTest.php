<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Support\ConvoLabAccountSecurityRateLimiter;
use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use App\Domain\Content\Support\ContentAudioRateLimiter;
use App\Domain\Content\Support\ContentAudioScriptRateLimiter;
use App\Domain\Content\Support\ContentCourseRateLimiter;
use App\Domain\Content\Support\ContentDialogueRateLimiter;
use App\Domain\Content\Support\ContentEpisodeRateLimiter;
use App\Domain\Content\Support\ContentImageRateLimiter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

class ConvoLabBrowserRateLimiterTest extends TestCase
{
    public function test_account_limiters_ignore_spoofed_proxy_identity_for_browser_sessions(): void
    {
        [$request, $convoLabUserId] = $this->browserRequest();
        $identityHash = hash('sha256', $convoLabUserId);

        $this->assertSame(
            ConvoLabProfileRateLimiter::NAME.':'.$identityHash.':192.0.2.15',
            ConvoLabProfileRateLimiter::limit($request)->key,
        );
        $this->assertSame(
            ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE.':'.$identityHash.':unknown-ip',
            ConvoLabAccountSecurityRateLimiter::limits(
                ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE,
                $request,
            )[0]->key,
        );
        $this->assertSame(
            ConvoLabVerificationRateLimiter::SEND.':'.$identityHash.':192.0.2.15',
            ConvoLabVerificationRateLimiter::forSend()->limit($request)->key,
        );
        $this->assertSame(
            ConvoLabOAuthRateLimiter::DISCONNECT.'|user:'.$identityHash,
            ConvoLabOAuthRateLimiter::authenticated(
                ConvoLabOAuthRateLimiter::DISCONNECT,
                $request,
            )[0]->key,
        );
    }

    public function test_content_limiters_ignore_spoofed_proxy_identity_for_browser_sessions(): void
    {
        [$request, $convoLabUserId] = $this->browserRequest();

        foreach ([
            [ContentImageRateLimiter::generation($request), ContentImageRateLimiter::GENERATION_NAME],
            [ContentAudioRateLimiter::generation($request), ContentAudioRateLimiter::GENERATION_NAME],
            [ContentDialogueRateLimiter::generation($request), ContentDialogueRateLimiter::GENERATION_NAME],
            [ContentEpisodeRateLimiter::create()->limit($request), ContentEpisodeRateLimiter::CREATE_NAME],
            [ContentAudioScriptRateLimiter::generation($request), ContentAudioScriptRateLimiter::GENERATION_NAME],
            [ContentCourseRateLimiter::forCreate()->limit($request), ContentCourseRateLimiter::CREATE_NAME],
        ] as [$limit, $name]) {
            $this->assertSame($name.':user:'.$convoLabUserId, $limit->key);
        }
    }

    /** @return array{Request, string} */
    private function browserRequest(): array
    {
        $convoLabUserId = (string) Str::uuid();
        $user = new User;
        $user->setAttribute('id', 42);
        $user->setAttribute('convolab_id', $convoLabUserId);
        $user->withAccessToken(new TransientToken, true);

        $request = Request::create(
            '/api/convolab/auth/me',
            'PATCH',
            server: ['REMOTE_ADDR' => '192.0.2.15'],
        );
        $session = new Store('browser-rate-limit', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $request->attributes->set('sanctum', true);
        $request->setUserResolver(fn (): User => $user);
        $request->headers->set('X-Convo-Lab-User-Id', (string) Str::uuid());

        return [$request, $convoLabUserId];
    }
}
