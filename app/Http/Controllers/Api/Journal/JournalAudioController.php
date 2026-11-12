<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\GenerateJournalAudio;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Support\JournalAudioState;
use App\Domain\Journal\Support\JournalAudioVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\JournalAudioRequest;
use App\Support\Audio\AudioSpeechGenerationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalAudioController extends Controller
{
    public function state(JournalAudioRequest $request, JournalEntry $entry): JsonResponse
    {
        Gate::authorize('view', $entry);
        $text = JournalAudioVersion::text($entry, $request->validated());

        return response()->json(['data' => JournalAudioState::selection($entry, $text)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function voices(): JsonResponse
    {
        return response()->json(['data' => collect(config('journal_voices'))
            ->map(fn (array $voice, string $id) => [
                'id' => $id, 'name' => $voice['name'].' ('.$voice['gender'].')',
            ])->values()]);
    }

    public function audio(JournalAudioRequest $request, JournalEntry $entry, GenerateJournalAudio $generate): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $entry);
        try {
            $path = $generate->handle($entry, $request->validated());
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'Audio is being prepared. Try again shortly.'], 409);
        } catch (AudioSpeechGenerationException) {
            return response()->json(['message' => 'Audio is temporarily unavailable. Please try again.'], 503);
        }
        abort_unless(JournalEntry::whereKey($entry->id)->exists(), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
