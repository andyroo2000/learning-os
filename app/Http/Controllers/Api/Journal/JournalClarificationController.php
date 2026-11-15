<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\AnswerJournalClarification;
use App\Domain\Journal\Actions\DispatchJournalClarification;
use App\Domain\Journal\Actions\StartJournalClarification;
use App\Domain\Journal\Data\JournalClarificationAnswer;
use App\Domain\Journal\Models\JournalClarification;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\AnswerJournalClarificationRequest;
use App\Http\Requests\Journal\StartJournalClarificationRequest;
use App\Http\Resources\Journal\JournalClarificationResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class JournalClarificationController extends Controller
{
    public function __construct(private readonly DispatchJournalClarification $dispatch) {}

    public function start(StartJournalClarificationRequest $request, string $entryId, StartJournalClarification $start): JsonResponse
    {
        $state = $start->handle(AuthenticatedUser::id($request), $entryId, (int) $request->validated('expectedVersion'));

        return response()->json(['data' => new JournalClarificationResource($this->dispatch->handle($state))], 202);
    }

    public function answer(AnswerJournalClarificationRequest $request, string $entryId, string $clarificationId, AnswerJournalClarification $answer): JsonResponse
    {
        $input = new JournalClarificationAnswer($clarificationId, $request->validated('questionId'), $request->validated('answer'));
        $state = $answer->handle(AuthenticatedUser::id($request), $entryId, (int) $request->validated('expectedVersion'), $input);

        return response()->json(['data' => new JournalClarificationResource($this->dispatch->handle($state))], 202);
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        Gate::authorize('view', $entry);
        // Deletion can win after route binding; never poll a deleted entry.
        $entry = JournalEntry::findOrFail($entry->id);
        $state = JournalClarification::where('entry_id', $entry->id)->where('source_version', $entry->version)
            ->where('source_body', $entry->body)->first();

        return response()->json(['data' => $state === null ? null : new JournalClarificationResource($state)]);
    }
}
