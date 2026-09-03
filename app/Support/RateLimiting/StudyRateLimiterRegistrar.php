<?php

namespace App\Support\RateLimiting;

use App\Domain\Flashcards\Support\NewCardQueueReorderRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventCreateRateLimiter;
use App\Domain\Reviews\Support\CardReviewEventUndoRateLimiter;
use App\Domain\Study\Support\DailyAudioPracticeGenerationRateLimiter;
use App\Domain\Study\Support\StudyActivitySessionRateLimiter;
use App\Domain\Study\Support\StudyCardActionRateLimiter;
use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Domain\Study\Support\StudyCardPitchAccentRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyImportRateLimiter;
use App\Domain\Study\Support\StudySessionStartRateLimiter;
use App\Domain\Study\Support\StudySettingsUpdateRateLimiter;
use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class StudyRateLimiterRegistrar
{
    public static function register(): void
    {
        self::registerStudyCardRateLimiters();
        self::registerStudyDraftRateLimiters();
        self::registerStudyImportRateLimiters();
        self::registerStudySessionAndReviewRateLimiters();
    }

    private static function registerStudyCardRateLimiters(): void
    {
        $studyActivitySessionRateLimiter = new StudyActivitySessionRateLimiter;
        RateLimiter::for(StudyActivitySessionRateLimiter::NAME, function (Request $request) use ($studyActivitySessionRateLimiter): Limit {
            return $studyActivitySessionRateLimiter->limit($request);
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

        $studyCardPitchAccentRateLimiter = new StudyCardPitchAccentRateLimiter;
        RateLimiter::for(StudyCardPitchAccentRateLimiter::NAME, function (Request $request) use ($studyCardPitchAccentRateLimiter): Limit {
            return $studyCardPitchAccentRateLimiter->limit($request);
        });
    }

    private static function registerStudyDraftRateLimiters(): void
    {
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

        $studyVocabBundleDraftRateLimiter = new StudyVocabBundleDraftRateLimiter;
        RateLimiter::for(StudyVocabBundleDraftRateLimiter::NAME, function (Request $request) use ($studyVocabBundleDraftRateLimiter): Limit {
            return $studyVocabBundleDraftRateLimiter->limit($request);
        });
    }

    private static function registerStudyImportRateLimiters(): void
    {
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
    }

    private static function registerStudySessionAndReviewRateLimiters(): void
    {
        $studySettingsUpdateRateLimiter = new StudySettingsUpdateRateLimiter;
        RateLimiter::for(StudySettingsUpdateRateLimiter::NAME, function (Request $request) use ($studySettingsUpdateRateLimiter): Limit {
            return $studySettingsUpdateRateLimiter->limit($request);
        });

        $studySessionStartRateLimiter = new StudySessionStartRateLimiter;
        RateLimiter::for(StudySessionStartRateLimiter::NAME, function (Request $request) use ($studySessionStartRateLimiter): Limit {
            return $studySessionStartRateLimiter->limit($request);
        });

        $dailyAudioPracticeGenerationRateLimiter = new DailyAudioPracticeGenerationRateLimiter;
        RateLimiter::for(DailyAudioPracticeGenerationRateLimiter::NAME, function (Request $request) use ($dailyAudioPracticeGenerationRateLimiter): Limit {
            return $dailyAudioPracticeGenerationRateLimiter->limit($request);
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
    }
}
