<?php

namespace Tests\Unit\Calendar;

use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Domain\Calendar\Services\SocialiteGoogleCalendarOAuthClient;
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
