<?php

namespace App\Http\Controllers\Web\Auth;

use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Support\ConvoLabBrowserOAuthSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class BeginConvoLabGoogleOAuthController extends Controller
{
    public function __invoke(
        Request $request,
        ConvoLabGoogleOAuthClient $google,
    ): RedirectResponse {
        ConvoLabBrowserOAuthSession::forget($request);
        $request->session()->regenerate(true);

        return $google->redirect();
    }
}
