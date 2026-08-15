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
        $scopes = array_values(array_filter(
            is_array($user->approvedScopes) ? $user->approvedScopes : [],
            static fn (mixed $scope): bool => is_string($scope) && $scope !== '',
        ));

        if (! is_string($providerId) || trim($providerId) === '' || strlen(trim($providerId)) > 255
            || ! is_string($email) || strlen(trim($email)) > 254
            || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false
            || ! filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new GoogleCalendarOAuthException('invalid_profile');
        }
        if (! is_string($user->token) || trim($user->token) === '' || ! is_int($user->expiresIn) || $user->expiresIn <= 0) {
            throw new GoogleCalendarOAuthException('missing_token');
        }
        if (! in_array(self::CALENDAR_SCOPE, $scopes, true)) {
            throw new GoogleCalendarOAuthException('missing_scope');
        }

        return new GoogleCalendarOAuthGrant(
            trim($providerId),
            trim($email),
            $user->token,
            is_string($user->refreshToken) && trim($user->refreshToken) !== '' ? $user->refreshToken : null,
            $user->expiresIn,
            $scopes,
        );
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
