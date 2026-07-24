<?php

namespace App\Http\Requests\Content;

abstract class ConvoLabVerifiedGenerationRequest extends ConvoLabContentWriteRequest
{
    protected function requiresVerifiedEmail(): bool
    {
        return true;
    }

    protected function blocksDemoMutation(): bool
    {
        return true;
    }
}
