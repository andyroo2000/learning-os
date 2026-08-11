<?php

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Support\StudyCardAudioPrepareRateLimiter;
use App\Domain\Study\Support\StudyCardCreateRateLimiter;
use App\Domain\Study\Support\StudyCardDraftAutosaveRateLimiter;
use App\Domain\Study\Support\StudyCardDraftDeleteRateLimiter;
use App\Domain\Study\Support\StudyCardDraftRetryRateLimiter;
use App\Domain\Study\Support\StudyCardPitchAccentRateLimiter;
use App\Domain\Study\Support\StudyCardUpdateRateLimiter;
use App\Domain\Study\Support\StudyCompatibilityTrafficRateLimiter;
use App\Domain\Study\Support\StudyVocabBundleDraftRateLimiter;
use App\Http\Controllers\Api\Study\DeleteStudyCardDraftController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewAudioController;
use App\Http\Controllers\Api\Study\GenerateStudyCardDraftPreviewImageController;
use App\Http\Controllers\Api\Study\ListStudyCardDraftsController;
use App\Http\Controllers\Api\Study\PrepareStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardAnswerAudioController;
use App\Http\Controllers\Api\Study\RegenerateStudyCardImageController;
use App\Http\Controllers\Api\Study\ResolveStudyCardPitchAccentController;
use App\Http\Controllers\Api\Study\RetryStudyCardDraftController;
use App\Http\Controllers\Api\Study\ShowStudyCardDraftController;
use App\Http\Controllers\Api\Study\StoreStudyCardDraftController;
use App\Http\Controllers\Api\Study\StoreStudyCardFromDraftController;
use App\Http\Controllers\Api\Study\StoreStudyVocabBundleDraftsController;
use App\Http\Controllers\Api\Study\UpdateStudyCardDraftController;
use App\Http\Controllers\Api\Study\UploadStudyCardImageController;
use Illuminate\Support\Facades\Route;

return static function (): void {
    Route::get('/study/card-drafts', ListStudyCardDraftsController::class)
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    Route::get('/study/card-drafts/{draftId}', ShowStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCompatibilityTrafficRateLimiter::READ_NAME);
    // Drafts, commits, and final manual cards share one user-scoped request bucket.
    Route::post('/study/card-drafts/{draftId}/card', StoreStudyCardFromDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    // ConvoLab path alias; this backend still requires a client card ID for retry-safe commits.
    Route::post('/study/card-drafts/{draftId}/create-card', StoreStudyCardFromDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::post('/study/card-drafts', StoreStudyCardDraftController::class)
        ->middleware('throttle:'.StudyCardCreateRateLimiter::NAME);
    Route::post('/study/card-candidates/vocab-bundle/drafts', StoreStudyVocabBundleDraftsController::class)
        ->middleware('throttle:'.StudyVocabBundleDraftRateLimiter::NAME);
    Route::patch('/study/card-drafts/{draftId}', UpdateStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardDraftAutosaveRateLimiter::NAME);
    // Provider actions consume one shared 10/min user spend budget after payload validation.
    Route::post('/study/card-drafts/{draftId}/preview-audio', GenerateStudyCardDraftPreviewAudioController::class)
        ->whereUlid('draftId');
    Route::post('/study/card-drafts/{draftId}/preview-image', GenerateStudyCardDraftPreviewImageController::class)
        ->whereUlid('draftId');
    Route::post('/study/cards/{cardId}/regenerate-answer-audio', RegenerateStudyCardAnswerAudioController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN);
    Route::post('/study/cards/{cardId}/regenerate-image', RegenerateStudyCardImageController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN);
    Route::post('/study/cards/{cardId}/image', UploadStudyCardImageController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardUpdateRateLimiter::NAME);
    Route::post('/study/cards/{cardId}/pitch-accent', ResolveStudyCardPitchAccentController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardPitchAccentRateLimiter::NAME);
    Route::post('/study/cards/{cardId}/prepare-answer-audio', PrepareStudyCardAnswerAudioController::class)
        ->where('cardId', Card::CLIENT_ID_ROUTE_PATTERN)
        ->middleware('throttle:'.StudyCardAudioPrepareRateLimiter::NAME);
    // Manual generation retries use their own 30/min user bucket so create/autosave retries cannot starve them.
    Route::post('/study/card-drafts/{draftId}/retry', RetryStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardDraftRetryRateLimiter::NAME);
    Route::delete('/study/card-drafts/{draftId}', DeleteStudyCardDraftController::class)
        ->whereUlid('draftId')
        ->middleware('throttle:'.StudyCardDraftDeleteRateLimiter::NAME);
};
