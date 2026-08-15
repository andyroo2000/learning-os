<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Domain\Calendar\Services\SocialiteGoogleCalendarOAuthClient;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class SocialiteGoogleCalendarOAuthClientTest extends TestCase
{
    public function test_redirect_uses_separate_incremental_offline_calendar_consent(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('setScopes')->once()->with(['openid', 'email', SocialiteGoogleCalendarOAuthClient::CALENDAR_SCOPE])->andReturnSelf();
        $provider->shouldReceive('with')->once()->with([
            'access_type' => 'offline', 'include_granted_scopes' => 'true', 'prompt' => 'consent select_account',
        ])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn($redirect = new RedirectResponse('https://accounts.google.test'));
        Socialite::shouldReceive('buildProvider')->once()->with(GoogleProvider::class, config('services.google_calendar'))->andReturn($provider);

        $this->assertSame($redirect, app(SocialiteGoogleCalendarOAuthClient::class)->redirect());
    }

    public function test_grant_maps_verified_tokens_and_approved_scopes(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn(User::fake([
            'id' => ' subject ', 'email' => ' andrew@example.com ', 'email_verified' => true,
            'token' => 'access', 'refreshToken' => 'refresh', 'expiresIn' => 3600,
            'approvedScopes' => [SocialiteGoogleCalendarOAuthClient::CALENDAR_SCOPE],
        ]));
        Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

        $grant = app(SocialiteGoogleCalendarOAuthClient::class)->grant();

        $this->assertSame('subject', $grant->providerAccountId);
        $this->assertSame('andrew@example.com', $grant->email);
        $this->assertSame('refresh', $grant->refreshToken);
    }

    public function test_native_authorization_and_grant_are_stateless_with_explicit_state(): void
    {
        $redirectProvider = new GoogleProvider(
            Request::create('/api/study/google-calendar/connect', 'POST'),
            'client-id',
            'client-secret',
            'https://convo-lab.test/api/study/google-calendar/callback',
        );
        Socialite::shouldReceive('buildProvider')->once()->andReturn($redirectProvider);

        $url = app(SocialiteGoogleCalendarOAuthClient::class)->authorizationUrl('explicit-state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('explicit-state', $query['state']);
        $this->assertSame('https://convo-lab.test/api/study/google-calendar/callback', $query['redirect_uri']);
        $this->assertStringContainsString(SocialiteGoogleCalendarOAuthClient::CALENDAR_SCOPE, $query['scope']);

        $grantProvider = Mockery::mock(GoogleProvider::class);
        $grantProvider->shouldReceive('stateless')->once()->andReturnSelf();
        $grantProvider->shouldReceive('user')->once()->andReturn(User::fake([
            'id' => 'subject', 'email' => 'andrew@example.com', 'email_verified' => true,
            'token' => 'access', 'refreshToken' => 'refresh', 'expiresIn' => 3600,
            'approvedScopes' => [SocialiteGoogleCalendarOAuthClient::CALENDAR_SCOPE],
        ]));
        Socialite::shouldReceive('buildProvider')->once()->andReturn($grantProvider);

        $this->assertSame('subject', app(SocialiteGoogleCalendarOAuthClient::class)->statelessGrant()->providerAccountId);
    }

    public function test_grant_rejects_missing_calendar_scope(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn(User::fake([
            'email_verified' => true, 'approvedScopes' => ['openid'],
        ]));
        Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);

        try {
            app(SocialiteGoogleCalendarOAuthClient::class)->grant();
            $this->fail('Expected the missing calendar scope to be rejected.');
        } catch (GoogleCalendarOAuthException $exception) {
            $this->assertSame('missing_scope', $exception->reason());
        }
    }
}
