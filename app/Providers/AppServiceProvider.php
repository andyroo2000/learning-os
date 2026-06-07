<?php

namespace App\Providers;

use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
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

        $authEmailRateLimiter = new AuthEmailRateLimiter;
        RateLimiter::for(AuthEmailRateLimiter::MOBILE_TOKENS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->mobileTokens($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::MOBILE_REGISTRATIONS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->mobileRegistrations($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::PASSWORD_RESET_LINKS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->passwordResetLinks($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::PASSWORD_RESET_TOKENS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->passwordResetTokens($request);
        });

        $studyCardCreateRateLimiter = new StudyCardCreateRateLimiter;
        RateLimiter::for(StudyCardCreateRateLimiter::NAME, function (Request $request) use ($studyCardCreateRateLimiter): Limit {
            return $studyCardCreateRateLimiter->limit($request);
        });

        $studyCardDeleteRateLimiter = new StudyCardDeleteRateLimiter;
        RateLimiter::for(StudyCardDeleteRateLimiter::NAME, function (Request $request) use ($studyCardDeleteRateLimiter): Limit {
            return $studyCardDeleteRateLimiter->limit($request);
        });

        $studyCardUpdateRateLimiter = new StudyCardUpdateRateLimiter;
        RateLimiter::for(StudyCardUpdateRateLimiter::NAME, function (Request $request) use ($studyCardUpdateRateLimiter): Limit {
            return $studyCardUpdateRateLimiter->limit($request);
        });

        $studyCardActionRateLimiter = new StudyCardActionRateLimiter;
        RateLimiter::for(StudyCardActionRateLimiter::NAME, function (Request $request) use ($studyCardActionRateLimiter): Limit {
            return $studyCardActionRateLimiter->limit($request);
        });

        $studyCardDraftAutosaveRateLimiter = new StudyCardDraftAutosaveRateLimiter;
        RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($studyCardDraftAutosaveRateLimiter): Limit {
            return $studyCardDraftAutosaveRateLimiter->limit($request);
        });

        $studyCardDraftDeleteRateLimiter = new StudyCardDraftDeleteRateLimiter;
        RateLimiter::for(StudyCardDraftDeleteRateLimiter::NAME, function (Request $request) use ($studyCardDraftDeleteRateLimiter): Limit {
            return $studyCardDraftDeleteRateLimiter->limit($request);
        });

        $studySettingsUpdateRateLimiter = new StudySettingsUpdateRateLimiter;
        RateLimiter::for(StudySettingsUpdateRateLimiter::NAME, function (Request $request) use ($studySettingsUpdateRateLimiter): Limit {
            return $studySettingsUpdateRateLimiter->limit($request);
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
