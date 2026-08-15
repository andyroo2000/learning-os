<?php

namespace App\Http\Controllers\Web\Calendar;

use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class BeginGoogleCalendarOAuthController extends Controller
{
    public function __invoke(Request $request, GoogleCalendarOAuthClient $google): RedirectResponse
    {
        $request->session()->put('google_calendar_oauth_user_id', AuthenticatedUser::id($request));

        return $google->redirect();
    }
}
