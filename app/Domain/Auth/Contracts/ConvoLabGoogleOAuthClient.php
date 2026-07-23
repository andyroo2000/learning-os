<?php

namespace App\Domain\Auth\Contracts;

use App\Domain\Auth\Data\ConvoLabGoogleProfile;
use Symfony\Component\HttpFoundation\RedirectResponse;

interface ConvoLabGoogleOAuthClient
{
    public function redirect(): RedirectResponse;

    public function user(): ConvoLabGoogleProfile;
}
