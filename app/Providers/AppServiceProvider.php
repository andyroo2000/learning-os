<?php

namespace App\Providers;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Support\CourseRateLimiter;
use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Japanese\Support\JapaneseKnowledgeRateLimiter;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Support\CardMediaRateLimiter;
use App\Domain\Media\Support\MediaAssetRateLimiter;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Domain\Study\Support\StudyMediaGenerationRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
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

        $accountProfileUpdateRateLimiter = AuthAccountRateLimiter::forProfileUpdate();
        RateLimiter::for(AuthAccountRateLimiter::PROFILE_UPDATE, function (Request $request) use ($accountProfileUpdateRateLimiter): Limit {
            return $accountProfileUpdateRateLimiter->limit($request);
        });

        $accountPasswordUpdateRateLimiter = AuthAccountRateLimiter::forPasswordUpdate();
        RateLimiter::for(AuthAccountRateLimiter::PASSWORD_UPDATE, function (Request $request) use ($accountPasswordUpdateRateLimiter): Limit {
            return $accountPasswordUpdateRateLimiter->limit($request);
        });

        $accountTokenRevokeRateLimiter = AuthAccountRateLimiter::forTokenRevoke();
        RateLimiter::for(AuthAccountRateLimiter::TOKEN_REVOKE, function (Request $request) use ($accountTokenRevokeRateLimiter): Limit {
            return $accountTokenRevokeRateLimiter->limit($request);
        });

        $courseCreateRateLimiter = CourseRateLimiter::create();
        RateLimiter::for(CourseRateLimiter::CREATE_NAME, function (Request $request) use ($courseCreateRateLimiter): Limit {
            return $courseCreateRateLimiter->limit($request);
        });

        $courseUpdateRateLimiter = CourseRateLimiter::update();
        RateLimiter::for(CourseRateLimiter::UPDATE_NAME, function (Request $request) use ($courseUpdateRateLimiter): Limit {
            return $courseUpdateRateLimiter->limit($request);
        });

        $courseDeleteRateLimiter = CourseRateLimiter::delete();
        RateLimiter::for(CourseRateLimiter::DELETE_NAME, function (Request $request) use ($courseDeleteRateLimiter): Limit {
            return $courseDeleteRateLimiter->limit($request);
        });

        // Very large offline deck-create backlogs can still throttle before idempotent de-dupe.
        $deckCreateRateLimiter = DeckRateLimiter::forCreate();
        RateLimiter::for(DeckRateLimiter::CREATE_NAME, function (Request $request) use ($deckCreateRateLimiter): Limit {
            return $deckCreateRateLimiter->limit($request);
        });

        $deckUpdateRateLimiter = DeckRateLimiter::forUpdate();
        RateLimiter::for(DeckRateLimiter::UPDATE_NAME, function (Request $request) use ($deckUpdateRateLimiter): Limit {
            return $deckUpdateRateLimiter->limit($request);
        });

        $deckDeleteRateLimiter = DeckRateLimiter::forDelete();
        RateLimiter::for(DeckRateLimiter::DELETE_NAME, function (Request $request) use ($deckDeleteRateLimiter): Limit {
            return $deckDeleteRateLimiter->limit($request);
        });

        $cardMediaAttachRateLimiter = CardMediaRateLimiter::forAttach();
        RateLimiter::for(CardMediaRateLimiter::ATTACH_NAME, function (Request $request) use ($cardMediaAttachRateLimiter): Limit {
            return $cardMediaAttachRateLimiter->limit($request);
        });

        $cardMediaDetachRateLimiter = CardMediaRateLimiter::forDetach();
        RateLimiter::for(CardMediaRateLimiter::DETACH_NAME, function (Request $request) use ($cardMediaDetachRateLimiter): Limit {
            return $cardMediaDetachRateLimiter->limit($request);
        });

        $mediaAssetCreateRateLimiter = MediaAssetRateLimiter::forCreate();
        RateLimiter::for(MediaAssetRateLimiter::CREATE_NAME, function (Request $request) use ($mediaAssetCreateRateLimiter): Limit {
            return $mediaAssetCreateRateLimiter->limit($request);
        });

        $mediaAssetDeleteRateLimiter = MediaAssetRateLimiter::forDelete();
        RateLimiter::for(MediaAssetRateLimiter::DELETE_NAME, function (Request $request) use ($mediaAssetDeleteRateLimiter): Limit {
            return $mediaAssetDeleteRateLimiter->limit($request);
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

        $studyCardAudioPrepareRateLimiter = new StudyCardAudioPrepareRateLimiter;
        RateLimiter::for(StudyCardAudioPrepareRateLimiter::NAME, function (Request $request) use ($studyCardAudioPrepareRateLimiter): Limit {
            return $studyCardAudioPrepareRateLimiter->limit($request);
        });

        $studyCardDraftAutosaveRateLimiter = new StudyCardDraftAutosaveRateLimiter;
        RateLimiter::for(StudyCardDraftAutosaveRateLimiter::NAME, function (Request $request) use ($studyCardDraftAutosaveRateLimiter): Limit {
            return $studyCardDraftAutosaveRateLimiter->limit($request);
        });

        $studyCardDraftDeleteRateLimiter = new StudyCardDraftDeleteRateLimiter;
        RateLimiter::for(StudyCardDraftDeleteRateLimiter::NAME, function (Request $request) use ($studyCardDraftDeleteRateLimiter): Limit {
            return $studyCardDraftDeleteRateLimiter->limit($request);
        });

        $studyCardDraftRetryRateLimiter = new StudyCardDraftRetryRateLimiter;
        RateLimiter::for(StudyCardDraftRetryRateLimiter::NAME, function (Request $request) use ($studyCardDraftRetryRateLimiter): Limit {
            return $studyCardDraftRetryRateLimiter->limit($request);
        });

        $studyMediaGenerationRateLimiter = new StudyMediaGenerationRateLimiter;
        RateLimiter::for(StudyMediaGenerationRateLimiter::NAME, function (Request $request) use ($studyMediaGenerationRateLimiter): Limit {
            return $studyMediaGenerationRateLimiter->limit($request);
        });

        $studyVocabBundleDraftRateLimiter = new StudyVocabBundleDraftRateLimiter;
        RateLimiter::for(StudyVocabBundleDraftRateLimiter::NAME, function (Request $request) use ($studyVocabBundleDraftRateLimiter): Limit {
            return $studyVocabBundleDraftRateLimiter->limit($request);
        });

        $studyImportCreateRateLimiter = StudyImportRateLimiter::forCreateSession();
        RateLimiter::for(StudyImportRateLimiter::CREATE_NAME, function (Request $request) use ($studyImportCreateRateLimiter): Limit {
            return $studyImportCreateRateLimiter->limit($request);
        });

        $studyImportUploadRateLimiter = StudyImportRateLimiter::forUpload();
        RateLimiter::for(StudyImportRateLimiter::UPLOAD_NAME, function (Request $request) use ($studyImportUploadRateLimiter): Limit {
            return $studyImportUploadRateLimiter->limit($request);
        });

        $studyImportCompleteRateLimiter = StudyImportRateLimiter::forComplete();
        RateLimiter::for(StudyImportRateLimiter::COMPLETE_NAME, function (Request $request) use ($studyImportCompleteRateLimiter): Limit {
            return $studyImportCompleteRateLimiter->limit($request);
        });

        $studyImportCancelRateLimiter = StudyImportRateLimiter::forCancel();
        RateLimiter::for(StudyImportRateLimiter::CANCEL_NAME, function (Request $request) use ($studyImportCancelRateLimiter): Limit {
            return $studyImportCancelRateLimiter->limit($request);
        });

        $studySettingsUpdateRateLimiter = new StudySettingsUpdateRateLimiter;
        RateLimiter::for(StudySettingsUpdateRateLimiter::NAME, function (Request $request) use ($studySettingsUpdateRateLimiter): Limit {
            return $studySettingsUpdateRateLimiter->limit($request);
        });

        $studySessionStartRateLimiter = new StudySessionStartRateLimiter;
        RateLimiter::for(StudySessionStartRateLimiter::NAME, function (Request $request) use ($studySessionStartRateLimiter): Limit {
            return $studySessionStartRateLimiter->limit($request);
        });

        $newCardQueueReorderRateLimiter = new NewCardQueueReorderRateLimiter;
        RateLimiter::for(NewCardQueueReorderRateLimiter::NAME, function (Request $request) use ($newCardQueueReorderRateLimiter): Limit {
            return $newCardQueueReorderRateLimiter->limit($request);
        });

        $cardReviewEventCreateRateLimiter = new CardReviewEventCreateRateLimiter;
        RateLimiter::for(CardReviewEventCreateRateLimiter::NAME, function (Request $request) use ($cardReviewEventCreateRateLimiter): Limit {
            return $cardReviewEventCreateRateLimiter->limit($request);
        });

        $cardReviewEventUndoRateLimiter = new CardReviewEventUndoRateLimiter;
        RateLimiter::for(CardReviewEventUndoRateLimiter::NAME, function (Request $request) use ($cardReviewEventUndoRateLimiter): Limit {
            return $cardReviewEventUndoRateLimiter->limit($request);
        });

        $wanikaniConnectionRateLimiter = JapaneseKnowledgeRateLimiter::forConnection();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::CONNECTION_NAME, function (Request $request) use ($wanikaniConnectionRateLimiter): Limit {
            return $wanikaniConnectionRateLimiter->limit($request);
        });

        $wanikaniSyncRateLimiter = JapaneseKnowledgeRateLimiter::forSync();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::SYNC_NAME, function (Request $request) use ($wanikaniSyncRateLimiter): Limit {
            return $wanikaniSyncRateLimiter->limit($request);
        });

        $knownKanjiManualRateLimiter = JapaneseKnowledgeRateLimiter::forManual();
        RateLimiter::for(JapaneseKnowledgeRateLimiter::MANUAL_NAME, function (Request $request) use ($knownKanjiManualRateLimiter): Limit {
            return $knownKanjiManualRateLimiter->limit($request);
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
