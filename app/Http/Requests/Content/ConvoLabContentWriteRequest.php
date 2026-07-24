<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabRequestIdentity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

abstract class ConvoLabContentWriteRequest extends ConvoLabContentUserRequest
{
    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this)
            && ! $this->isUnverifiedBrowserMutation()
            && ! $this->isBlockedDemoMutation();
    }

    protected function requiresVerifiedEmail(): bool
    {
        return false;
    }

    protected function blocksDemoMutation(): bool
    {
        return false;
    }

    protected function requiresActorRole(): bool
    {
        return $this->requiresVerifiedEmail() || $this->blocksDemoMutation();
    }

    protected function failedAuthorization(): void
    {
        if ($this->isUnverifiedBrowserMutation()) {
            throw new AuthorizationException(
                'Please verify your email address before generating content. '
                .'Check your inbox for the verification email.',
            );
        }

        if ($this->isBlockedDemoMutation()) {
            throw new AuthorizationException(
                "You're exploring in demo mode, so content creation is disabled. "
                ."Thanks for checking out the app! If you'd like full access, please contact the admin.",
            );
        }

        parent::failedAuthorization();
    }

    private function isUnverifiedBrowserMutation(): bool
    {
        if (! $this->requiresVerifiedEmail()
            || ! ConvoLabRequestIdentity::allowsFirstPartySession($this)
            || $this->actorIdentity()->role === 'admin'
        ) {
            return false;
        }

        // Verify the authenticated actor before applying admin viewAs.
        $user = $this->user();

        return $user instanceof User && $user->email_verified_at === null;
    }

    private function isBlockedDemoMutation(): bool
    {
        // Legacy Express applies blockDemoUser before resolving admin viewAs. Keep the
        // authenticated actor authoritative so admins can support a demo account.
        return $this->blocksDemoMutation() && $this->actorIdentity()->role === 'demo';
    }
}
