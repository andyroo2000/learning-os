<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Data\ConvoLabGoogleProfile;
use App\Domain\Auth\Support\ConvoLabBrowserOAuthSession;
use App\Domain\Auth\Support\ConvoLabBrowserReturnUrl;
use App\Models\User;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ConvoLabBrowserReturnRoutingTest extends ConvoLabBrowserSessionApiTestCase
{
    private MockInterface $google;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.convolab.client_url', self::FRONTEND_ORIGIN);
        $this->google = $this->mock(ConvoLabGoogleOAuthClient::class);
    }

    private function googleProfile(string $email): void
    {
        $this->google->shouldReceive('user')->once()->andReturn(new ConvoLabGoogleProfile(
            providerId: 'routing-subject', email: $email, name: 'Reader', avatarUrl: null, emailVerified: true,
        ));
    }

    public function test_google_start_replaces_a_stale_return_url_with_a_validated_target(): void
    {
        $this->google->shouldReceive('redirect')->twice()->andReturn(new RedirectResponse('https://accounts.google.test/authorize'));
        $target = '/app/journal/01abc?draft=1#correction';
        $this->withSession([ConvoLabBrowserReturnUrl::SESSION_KEY => '/app/admin'])
            ->get('/api/convolab/browser/auth/google?'.http_build_query(['returnUrl' => $target]))
            ->assertRedirect('https://accounts.google.test/authorize')
            ->assertSessionHas(ConvoLabBrowserReturnUrl::SESSION_KEY, $target);

        $this->get('/api/convolab/browser/auth/google?returnUrl[]=https://evil.example')
            ->assertRedirect('https://accounts.google.test/authorize')
            ->assertSessionHas(ConvoLabBrowserReturnUrl::SESSION_KEY, '/app/journal');
    }

    public function test_google_callback_uses_the_session_target_instead_of_callback_query_input(): void
    {
        $account = $this->projectedUser(['email' => 'ada@example.com', 'email_verified' => true, 'email_verified_at' => now()]);
        $this->googleProfile('ada@example.com');
        $target = '/app/journal/01abc?draft=1#correction';

        $this->withSession([ConvoLabBrowserReturnUrl::SESSION_KEY => $target])
            ->get('/api/convolab/browser/auth/google/callback?returnUrl=https://evil.example')
            ->assertRedirect(self::FRONTEND_ORIGIN.$target)
            ->assertSessionMissing(ConvoLabBrowserReturnUrl::SESSION_KEY);
        $this->assertAuthenticatedAs(User::findOrFail($account['user_id']), 'web');
    }

    public function test_google_callback_revalidates_a_stored_return_target(): void
    {
        $this->projectedUser(['email' => 'ada@example.com', 'email_verified' => true, 'email_verified_at' => now()]);
        $this->googleProfile('ada@example.com');
        $this->withSession([ConvoLabBrowserReturnUrl::SESSION_KEY => '//evil.example'])
            ->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/app/journal')
            ->assertSessionMissing(ConvoLabBrowserReturnUrl::SESSION_KEY);
    }

    public function test_google_invite_and_retry_pages_preserve_the_safe_return_target(): void
    {
        $target = '/app/journal/01abc';
        $query = http_build_query(['returnUrl' => $target]);
        $this->googleProfile('new@example.com');
        $this->withSession([ConvoLabBrowserReturnUrl::SESSION_KEY => $target])
            ->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/claim-invite?'.$query)
            ->assertSessionMissing(ConvoLabBrowserReturnUrl::SESSION_KEY);
        $this->assertGuest('web');

        $this->google->shouldReceive('user')->once()->andThrow(new \RuntimeException('provider failed'));
        $this->withSession([ConvoLabBrowserReturnUrl::SESSION_KEY => $target])
            ->get('/api/convolab/browser/auth/google/callback')
            ->assertRedirect(self::FRONTEND_ORIGIN.'/login?error=oauth_failed&'.$query)
            ->assertSessionMissing(ConvoLabBrowserReturnUrl::SESSION_KEY)
            ->assertSessionMissing(ConvoLabBrowserOAuthSession::PENDING_GOOGLE_ACCOUNT);
        $this->assertGuest('web');
    }
}
