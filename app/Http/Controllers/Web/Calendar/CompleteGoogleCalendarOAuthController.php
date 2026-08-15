<?php

namespace App\Http\Controllers\Web\Calendar;

use App\Domain\Calendar\Actions\ClaimGoogleCalendarConnectIntentAction;
use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Data\GoogleCalendarConnectIntentClaim;
use App\Domain\Calendar\Exceptions\GoogleCalendarOAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

final class CompleteGoogleCalendarOAuthController extends Controller
{
    private const SESSION_USER_ID = 'google_calendar_oauth_user_id';

    public function __invoke(
        Request $request,
        GoogleCalendarOAuthClient $google,
        ConnectGoogleCalendarAction $connect,
        ClaimGoogleCalendarConnectIntentAction $claimIntent,
    ): RedirectResponse {
        $intent = $claimIntent->handle($request->query('state'));
        if ($intent !== null) {
            return $this->completeIntent($request, $intent, $google, $connect);
        }

        $userId = $request->user('web')?->getAuthIdentifier();
        if (! is_int($userId) || $request->session()->get(self::SESSION_USER_ID) !== $userId) {
            $request->session()->forget([self::SESSION_USER_ID, 'state']);

            return $this->result('error', 'invalid_state');
        }

        try {
            $providerError = $request->query('error');
            if (is_string($providerError) && $providerError !== '') {
                $expectedState = $request->session()->get('state');
                $providedState = $request->query('state');
                if (! is_string($expectedState)
                    || ! is_string($providedState)
                    || ! hash_equals($expectedState, $providedState)) {
                    return $this->result('error', 'invalid_state');
                }

                return $this->result(
                    'error',
                    $providerError === 'access_denied' ? 'access_denied' : 'oauth_failed',
                );
            }

            $connect->handle($userId, $google->grant());

            return $this->result('connected');
        } catch (InvalidStateException) {
            return $this->result('error', 'invalid_state');
        } catch (GoogleCalendarOAuthException $exception) {
            return $this->result('error', $exception->reason());
        } catch (Throwable) {
            report(new RuntimeException('Google Calendar OAuth callback failed.'));

            return $this->result('error', 'oauth_failed');
        } finally {
            $request->session()->forget([self::SESSION_USER_ID, 'state']);
        }
    }

    private function completeIntent(
        Request $request,
        GoogleCalendarConnectIntentClaim $intent,
        GoogleCalendarOAuthClient $google,
        ConnectGoogleCalendarAction $connect,
    ): RedirectResponse {
        try {
            $providerError = $request->query('error');
            if (is_string($providerError) && $providerError !== '') {
                return $this->intentResult(
                    $intent->completionTarget,
                    'error',
                    $providerError === 'access_denied' ? 'access_denied' : 'oauth_failed',
                );
            }

            $connect->handle($intent->userId, $google->statelessGrant());

            return $this->intentResult($intent->completionTarget, 'connected');
        } catch (GoogleCalendarOAuthException $exception) {
            return $this->intentResult($intent->completionTarget, 'error', $exception->reason());
        } catch (Throwable) {
            report(new RuntimeException('Google Calendar OAuth callback failed.'));

            return $this->intentResult($intent->completionTarget, 'error', 'oauth_failed');
        }
    }

    private function result(string $status, ?string $reason = null): RedirectResponse
    {
        $query = ['calendarConnection' => $status];
        if ($reason !== null) {
            $query['reason'] = $reason;
        }

        $client = rtrim((string) config('services.convolab.client_url'), '/');

        return redirect()->away($client.'/app/study/time?'.http_build_query($query));
    }

    private function intentResult(string $target, string $status, ?string $reason = null): RedirectResponse
    {
        if ($target !== 'ios') {
            return $this->result($status, $reason);
        }

        $query = ['calendarConnection' => $status];
        if ($reason !== null) {
            $query['reason'] = $reason;
        }

        return redirect()->away('convolab://study-time?'.http_build_query($query));
    }
}
