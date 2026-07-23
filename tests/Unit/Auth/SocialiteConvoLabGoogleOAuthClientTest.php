<?php

namespace Tests\Unit\Auth;

use App\Domain\Auth\Services\SocialiteConvoLabGoogleOAuthClient;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User;
use Mockery;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class SocialiteConvoLabGoogleOAuthClientTest extends TestCase
{
    public function test_redirect_requests_identity_scopes_and_account_selection(): void
    {
        $redirect = new RedirectResponse('https://accounts.google.test/authorize');
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('scopes')
            ->once()
            ->with(['openid', 'profile', 'email'])
            ->andReturnSelf();
        $provider->shouldReceive('with')
            ->once()
            ->with(['prompt' => 'select_account'])
            ->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn($redirect);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $this->assertSame(
            $redirect,
            app(SocialiteConvoLabGoogleOAuthClient::class)->redirect(),
        );
    }

    public function test_user_maps_verified_identity_without_exposing_provider_tokens(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn(User::fake([
            'id' => ' google-subject ',
            'name' => ' Ada Lovelace ',
            'email' => ' ADA@example.com ',
            'avatar' => ' https://example.com/ada.png ',
            'email_verified' => 'true',
            'token' => 'must-not-leave-socialite',
            'refreshToken' => 'must-not-leave-socialite',
        ]));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $profile = app(SocialiteConvoLabGoogleOAuthClient::class)->user();

        $this->assertSame('google-subject', $profile->providerId);
        $this->assertSame('ADA@example.com', $profile->email);
        $this->assertSame('Ada Lovelace', $profile->name);
        $this->assertSame('https://example.com/ada.png', $profile->avatarUrl);
        $this->assertTrue($profile->emailVerified);
        $this->assertCount(5, get_object_vars($profile));
    }

    public function test_user_falls_back_to_email_for_a_missing_name_and_preserves_unverified_state(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn(User::fake([
            'id' => 'subject',
            'name' => null,
            'nickname' => null,
            'email' => 'nameless@example.com',
            'avatar' => null,
            'email_verified' => false,
        ]));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $profile = app(SocialiteConvoLabGoogleOAuthClient::class)->user();

        $this->assertSame('nameless@example.com', $profile->name);
        $this->assertNull($profile->avatarUrl);
        $this->assertFalse($profile->emailVerified);
    }
}
