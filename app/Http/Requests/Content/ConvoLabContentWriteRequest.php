<?php

namespace App\Http\Requests\Content;

use App\Http\Support\ConvoLabRequestIdentity;
use Illuminate\Auth\Access\AuthorizationException;

abstract class ConvoLabContentWriteRequest extends ConvoLabContentUserRequest
{
    public function authorize(): bool
    {
        return ConvoLabRequestIdentity::allows($this, 'content:write')
            && ! $this->isBlockedDemoMutation();
    }

    protected function blocksDemoMutation(): bool
    {
        return false;
    }

    protected function requiresActorRole(): bool
    {
        return $this->blocksDemoMutation();
    }

    protected function failedAuthorization(): void
    {
        if ($this->isBlockedDemoMutation()) {
            throw new AuthorizationException(
                "You're exploring in demo mode, so content creation is disabled. "
                ."Thanks for checking out the app! If you'd like full access, please contact the admin.",
            );
        }

        parent::failedAuthorization();
    }

    private function isBlockedDemoMutation(): bool
    {
        return $this->blocksDemoMutation() && $this->actorIdentity()->role === 'demo';
    }
}
