<?php

namespace App\Providers;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Services\InterventionAdminAvatarImageProcessor;
use App\Domain\Analytics\Contracts\ToolAnalyticsLogger;
use App\Domain\Analytics\Services\JsonLineToolAnalyticsLogger;
use App\Domain\Auth\Contracts\ConvoLabGoogleOAuthClient;
use App\Domain\Auth\Services\SocialiteConvoLabGoogleOAuthClient;
use App\Domain\Calendar\Contracts\GoogleCalendarOAuthClient;
use App\Domain\Calendar\Contracts\GoogleCalendarReadTransport;
use App\Domain\Calendar\Services\LaravelGoogleCalendarReadTransport;
use App\Domain\Calendar\Services\SocialiteGoogleCalendarOAuthClient;
use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use App\Domain\Japanese\Services\MecabJapaneseTokenizer;
use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Contracts\StaticMediaObjectWriter;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Services\GoogleCloudStaticMediaObjectStore;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Services\OpenAiStudyImageGenerator;
use App\Policies\CardPolicy;
use App\Policies\CardReviewEventPolicy;
use App\Policies\CoursePolicy;
use App\Policies\DeckPolicy;
use App\Policies\MediaAssetPolicy;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator;
use App\Support\Images\ImageGenerator;
use App\Support\Queue\ProductionQueueConfiguration;
use App\Support\RateLimiting\ApplicationRateLimiterRegistrar;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            StaticMediaObjectStore::class,
            GoogleCloudStaticMediaObjectStore::class,
        );
        $this->app->singleton(
            StaticMediaObjectWriter::class,
            GoogleCloudStaticMediaObjectStore::class,
        );
        $this->app->singleton(
            AdminAvatarImageProcessor::class,
            InterventionAdminAvatarImageProcessor::class,
        );
        $this->app->bind(ToolAnalyticsLogger::class, JsonLineToolAnalyticsLogger::class);
        $this->app->bind(
            ConvoLabGoogleOAuthClient::class,
            SocialiteConvoLabGoogleOAuthClient::class,
        );
        $this->app->bind(GoogleCalendarOAuthClient::class, SocialiteGoogleCalendarOAuthClient::class);
        $this->app->bind(GoogleCalendarReadTransport::class, LaravelGoogleCalendarReadTransport::class);
        $this->app->bind(AudioSpeechGenerator::class, FishAudioSpeechGenerator::class);
        $this->app->bind(ImageGenerator::class, OpenAiStudyImageGenerator::class);
        $this->app->singleton(JapaneseTokenizer::class, MecabJapaneseTokenizer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $queueConnection = (string) config('queue.default');
        ProductionQueueConfiguration::assertSafe(
            environment: (string) $this->app->environment(),
            connection: $queueConnection,
            driver: config("queue.connections.{$queueConnection}.driver"),
        );

        // Keep policy wiring explicit while the API ownership model is still being shaped.
        Gate::policy(Card::class, CardPolicy::class);
        Gate::policy(CardReviewEvent::class, CardReviewEventPolicy::class);
        Gate::policy(Course::class, CoursePolicy::class);
        Gate::policy(Deck::class, DeckPolicy::class);
        Gate::policy(MediaAsset::class, MediaAssetPolicy::class);

        ApplicationRateLimiterRegistrar::register();

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
