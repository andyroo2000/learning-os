<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\GenerateJournalTimedAudio;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\JournalAudioRequest;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Identifiers\CanonicalUlid;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class JournalTimedAudioController extends Controller
{
    public function entry(JournalAudioRequest $request, JournalEntry $entry, GenerateJournalTimedAudio $generate): JsonResponse
    {
        Gate::authorize('view', $entry);

        return $this->respond(fn () => $generate->entry($entry, $request->validated()));
    }

    public function question(JournalEntry $entry, string $questionId, GenerateJournalTimedAudio $generate): JsonResponse
    {
        Gate::authorize('view', $entry);

        return $this->respond(fn () => $generate->question($entry, CanonicalUlid::normalize($questionId)));
    }

    private function respond(Closure $generate): JsonResponse
    {
        try {
            return response()->json(['data' => $generate()])->header('Cache-Control', 'private, no-store');
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Audio is being prepared. Try again shortly.'], 409);
        } catch (AudioSpeechGenerationException) {
            return response()->json(['message' => 'Audio is temporarily unavailable. Please try again.'], 503);
        }
    }
}
