<?php

namespace App\Domain\Auth\Services;

use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Data\ConvoLabGoogleProfile;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use UnexpectedValueException;

final class SocialiteConvoLabGoogleOAuthClient implements ConvoLabGoogleOAuthClient
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function user(): ConvoLabGoogleProfile
    {
        $user = Socialite::driver('google')->user();
        $providerId = $user->getId();
        $email = $user->getEmail();
        $name = $user->getName() ?: $user->getNickname();
        $avatarUrl = $user->getAvatar();
        $raw = $user->getRaw();

        if (! is_string($providerId) || trim($providerId) === '') {
            throw new UnexpectedValueException('Google did not return a subject identifier.');
        }
        if (! is_string($email) || trim($email) === '') {
            throw new UnexpectedValueException('Google did not return an email address.');
        }
        if (! is_string($name) || trim($name) === '') {
            $name = $email;
        }

        return new ConvoLabGoogleProfile(
            providerId: trim($providerId),
            email: trim($email),
            name: trim($name),
            avatarUrl: is_string($avatarUrl) && trim($avatarUrl) !== ''
                ? trim($avatarUrl)
                : null,
            emailVerified: filter_var(
                $raw['email_verified'] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
        );
    }
}
