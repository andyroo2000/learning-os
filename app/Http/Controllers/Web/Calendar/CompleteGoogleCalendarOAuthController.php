<?php

namespace App\Http\Controllers\Web\Calendar;

use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
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
    ): RedirectResponse {
        $userId = $request->user('web')?->getAuthIdentifier();
        if (! is_int($userId) || $request->session()->get(self::SESSION_USER_ID) !== $userId) {
            $request->session()->forget([self::SESSION_USER_ID, 'state']);

            return $this->result('error', 'invalid_state');
        }

        try {
            $providerError = $request->query('error');
            if (is_string($providerError) && $providerError !== '') {
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

    private function result(string $status, ?string $reason = null): RedirectResponse
    {
        $query = ['calendarConnection' => $status];
        if ($reason !== null) {
            $query['reason'] = $reason;
        }

        $client = rtrim((string) config('services.convolab.client_url'), '/');

        return redirect()->away($client.'/app/study/time?'.http_build_query($query));
    }
}
