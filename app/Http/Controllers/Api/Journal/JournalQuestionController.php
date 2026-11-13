<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\PrepareJournalQuestion;
use App\Domain\Journal\Actions\RetryJournalQuestionAudio;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Http\Controllers\Controller;
use App\Http\Resources\Journal\JournalQuestionResource;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JournalQuestionController extends Controller
{
    public function prepare(Request $request, JournalEntry $entry, PrepareJournalQuestion $prepare): JsonResponse
    {
        Gate::authorize('view', $entry);
        $question = $prepare->handle(AuthenticatedUser::id($request), $entry->id, $request->all());

        return $this->response($question);
    }

    public function retry(Request $request, JournalEntry $entry, string $questionId, RetryJournalQuestionAudio $retry): JsonResponse
    {
        Gate::authorize('view', $entry);

        return $this->response($retry->handle(AuthenticatedUser::id($request), $entry->id, $questionId));
    }

    public function show(JournalEntry $entry, string $questionId): JsonResponse
    {
        Gate::authorize('view', $entry);
        $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))->findOrFail(CanonicalUlid::normalize($questionId));

        return $this->response($question);
    }

    public function audio(JournalEntry $entry, string $questionId): StreamedResponse
    {
        Gate::authorize('view', $entry);
        $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))->findOrFail(CanonicalUlid::normalize($questionId));
        abort_unless($question->audio_status === 'ready' && Storage::disk('local')->exists($question->audio_path), 409, 'Question audio is not ready.');

        return Storage::disk('local')->response($question->audio_path, null, [
            'Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function response(JournalQuestion $question): JsonResponse
    {
        return response()->json(['data' => new JournalQuestionResource($question)])->header('Cache-Control', 'private, no-store');
    }
}
