<?php

namespace App\Domain\Calendar\Contracts;

use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use Symfony\Component\HttpFoundation\RedirectResponse;

interface GoogleCalendarOAuthClient
{
    public function redirect(): RedirectResponse;

    public function authorizationUrl(string $state): string;

    public function grant(): GoogleCalendarOAuthGrant;

    public function statelessGrant(): GoogleCalendarOAuthGrant;
}
