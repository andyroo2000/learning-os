<?php

namespace App\Domain\Calendar\Services;

use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class SocialiteGoogleCalendarOAuthClient implements GoogleCalendarOAuthClient
{
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';

    public function redirect(): RedirectResponse
    {
        return $this->consentProvider()->redirect();
    }

    public function authorizationUrl(string $state): string
    {
        return $this->consentProvider(['state' => $state])->stateless()->redirect()->getTargetUrl();
    }

    public function grant(): GoogleCalendarOAuthGrant
    {
        $user = $this->provider()->user();

        return $this->mapGrant($user);
    }

    public function statelessGrant(): GoogleCalendarOAuthGrant
    {
        return $this->mapGrant($this->provider()->stateless()->user());
    }

    private function mapGrant(User $user): GoogleCalendarOAuthGrant
    {
        $providerId = $user->getId();
        $email = $user->getEmail();
        $raw = $user->getRaw();
        $scopes = $this->approvedScopes($user);

        $this->assertValidProfile($providerId, $email, $raw);
        $this->assertValidAccessToken($user);
        $this->assertCalendarScope($scopes);

        return new GoogleCalendarOAuthGrant(
            trim($providerId),
            trim($email),
            $user->token,
            $this->refreshToken($user),
            $user->expiresIn,
            $scopes,
        );
    }

    /** @param array<string, mixed> $raw */
    private function assertValidProfile(mixed $providerId, mixed $email, array $raw): void
    {
        if (! $this->isValidProviderId($providerId)) {
            throw new GoogleCalendarOAuthException('invalid_profile');
        }

        if (! $this->isValidEmail($email)) {
            throw new GoogleCalendarOAuthException('invalid_profile');
        }

        if (! $this->isVerifiedProfile($raw)) {
            throw new GoogleCalendarOAuthException('invalid_profile');
        }
    }

    private function assertValidAccessToken(User $user): void
    {
        if (! $this->hasValidAccessToken($user)) {
            throw new GoogleCalendarOAuthException('missing_token');
        }
    }

    /** @param list<string> $scopes */
    private function assertCalendarScope(array $scopes): void
    {
        if (! in_array(self::CALENDAR_SCOPE, $scopes, true)) {
            throw new GoogleCalendarOAuthException('missing_scope');
        }
    }

    /** @return list<string> */
    private function approvedScopes(User $user): array
    {
        return array_values(array_filter(
            is_array($user->approvedScopes) ? $user->approvedScopes : [],
            static fn (mixed $scope): bool => is_string($scope) && $scope !== '',
        ));
    }

    private function isValidProviderId(mixed $providerId): bool
    {
        if (! is_string($providerId)) {
            return false;
        }

        $providerId = trim($providerId);

        return $providerId !== '' && strlen($providerId) <= 255;
    }

    private function isValidEmail(mixed $email): bool
    {
        if (! is_string($email)) {
            return false;
        }

        $email = trim($email);

        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @param array<string, mixed> $raw */
    private function isVerifiedProfile(array $raw): bool
    {
        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL) === true;
    }

    private function hasValidAccessToken(User $user): bool
    {
        return is_string($user->token)
            && trim($user->token) !== ''
            && is_int($user->expiresIn)
            && $user->expiresIn > 0;
    }

    private function refreshToken(User $user): ?string
    {
        if (! is_string($user->refreshToken) || trim($user->refreshToken) === '') {
            return null;
        }

        return $user->refreshToken;
    }

    private function provider(): GoogleProvider
    {
        /** @var GoogleProvider */
        return Socialite::buildProvider(GoogleProvider::class, config('services.google_calendar'));
    }

    /** @param array<string, string> $parameters */
    private function consentProvider(array $parameters = []): GoogleProvider
    {
        return $this->provider()
            ->setScopes(['openid', 'email', self::CALENDAR_SCOPE])
            ->with([
                'access_type' => 'offline',
                'include_granted_scopes' => 'true',
                'prompt' => 'consent select_account',
                ...$parameters,
            ]);
    }
}
