<?php

namespace App\Http\Controllers\Web\Auth;

use App\Domain\Auth\Actions\ResolveConvoLabGoogleIdentityAction;
use App\Domain\Auth\Actions\StartConvoLabBrowserSessionAction;
use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Support\ConvoLabBrowserOAuthSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class CompleteConvoLabGoogleOAuthController extends Controller
{
    public function __invoke(
        Request $request,
        ConvoLabGoogleOAuthClient $google,
        ResolveConvoLabGoogleIdentityAction $resolveIdentity,
        StartConvoLabBrowserSessionAction $startSession,
    ): RedirectResponse {
        try {
            $profile = $google->user();
            $result = $resolveIdentity->handle(
                providerId: $profile->providerId,
                email: $profile->email,
                name: $profile->name,
                avatarUrl: $profile->avatarUrl,
                emailVerified: $profile->emailVerified,
            );
        } catch (Throwable $exception) {
            ConvoLabBrowserOAuthSession::forget($request);
            report($exception);

            return redirect()->away($this->clientUrl('/login?error=oauth_failed'));
        }

        if ($result->requiresInvite) {
            ConvoLabBrowserOAuthSession::remember(
                $request,
                (string) $result->account->convolab_id,
            );

            return redirect()->away($this->clientUrl('/claim-invite'));
        }

        ConvoLabBrowserOAuthSession::forget($request);
        $startSession->handle($request, $result->account);

        return redirect()->away($this->clientUrl('/app/library'));
    }

    private function clientUrl(string $path): string
    {
        return rtrim((string) config('services.convolab.client_url'), '/').$path;
    }
}
