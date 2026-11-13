<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\PrepareJournalQuestion;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalQuestion;
use App\Http\Controllers\Controller;
use App\Http\Resources\Journal\JournalQuestionResource;
use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JournalQuestionController extends Controller
{
    public function prepare(Request $request, JournalEntry $entry, PrepareJournalQuestion $prepare): JsonResponse
    {
        Gate::authorize('view', $entry);
        $question = $prepare->handle(AuthenticatedUser::id($request), $entry->id, $request->all());

        return $this->response($question);
    }

    public function show(JournalEntry $entry, string $questionId): JsonResponse
    {
        Gate::authorize('view', $entry);
        $question = JournalQuestion::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))->findOrFail(CanonicalUlid::normalize($questionId));

        return $this->response($question);
    }

    private function response(JournalQuestion $question): JsonResponse
    {
        return response()->json(['data' => new JournalQuestionResource($question)])->header('Cache-Control', 'private, no-store');
    }
}
