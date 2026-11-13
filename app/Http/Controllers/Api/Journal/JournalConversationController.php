<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\CreateJournalConversationEntry;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\CreateJournalConversationRequest;
use App\Http\Resources\Journal\JournalConversationResource;
use App\Http\Resources\Journal\JournalEntryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class JournalConversationController extends Controller
{
    public function show(JournalEntry $entry): JsonResponse
    {
        Gate::authorize('view', $entry);
        abort_unless($entry->root_entry_id === null, 404);
        $children = JournalEntry::where('user_id', $entry->user_id)->where('root_entry_id', $entry->id)
            ->with(['latestAnalysis', 'answeredQuestion.analysis'])->orderBy('conversation_position')->get();

        return response()->json(['data' => JournalConversationResource::collection($children)]);
    }

    public function create(CreateJournalConversationRequest $request, string $entryId, CreateJournalConversationEntry $create): JsonResponse
    {
        // Creation is separate from submission: a queue/network failure leaves a saved,
        // editable answer that can be submitted through the normal entry endpoint.
        $entry = $create->handle(AuthenticatedUser::id($request), $entryId, $request->validated());

        return response()->json(['data' => new JournalEntryResource($entry)], $entry->wasRecentlyCreated ? 201 : 200);
    }
}
