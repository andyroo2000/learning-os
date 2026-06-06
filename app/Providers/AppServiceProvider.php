<?php

namespace App\Providers;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Policies\CardPolicy;
use App\Policies\CardReviewEventPolicy;
use App\Policies\CoursePolicy;
use App\Policies\DeckPolicy;
use App\Policies\MediaAssetPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep policy wiring explicit while the API ownership model is still being shaped.
        Gate::policy(Card::class, CardPolicy::class);
        Gate::policy(CardReviewEvent::class, CardReviewEventPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Deck::class, DeckPolicy::class);
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);

        RateLimiter::for('mobile-tokens', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(6)->by(($email !== '' ? $email : 'missing-email').'|'.$request->ip());
        });

        RateLimiter::for('mobile-registrations', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(6)->by(($email !== '' ? $email : 'missing-email').'|'.$request->ip());
        });

        RateLimiter::for('password-reset-links', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(6)->by(($email !== '' ? $email : 'missing-email').'|'.$request->ip());
        });

        RateLimiter::for('password-reset-tokens', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(12)->by(($email !== '' ? $email : 'missing-email').'|'.$request->ip());
        });

        $studyCardCreateRateLimiter = new StudyCardCreateRateLimiter;
        RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($studyCardCreateRateLimiter): Limit {
            return $studyCardCreateRateLimiter->limit($request);
        });

        $studyCardDraftAutosaveRateLimiter = new StudyCardDraftAutosaveRateLimiter;
        RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($studyCardDraftAutosaveRateLimiter): Limit {
            return $studyCardDraftAutosaveRateLimiter->limit($request);
        });

        // Current reset flows are API/client-link based; use per-flow notifications if web/admin URLs diverge.
        ResetPassword::createUrlUsing(function (CanResetPasswordContract $notifiable, string $token): string {
            $baseUrl = rtrim((string) config('app.password_reset_url'), '?&');
            $separator = str_contains($baseUrl, '?') ? '&' : '?';

            return $baseUrl.$separator.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }
}
