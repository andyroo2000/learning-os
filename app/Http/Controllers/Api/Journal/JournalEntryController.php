<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\DeleteJournalEntry;
use App\Domain\Journal\Actions\ListJournalEntries;
use App\Domain\Journal\Actions\SaveJournalDraft;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\DeleteJournalEntryRequest;
use App\Http\Requests\Journal\ListJournalEntriesRequest;
use App\Http\Requests\Journal\SaveJournalDraftRequest;
use App\Http\Resources\Journal\JournalEntryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class JournalEntryController extends Controller
{
    public function index(ListJournalEntriesRequest $request, ListJournalEntries $list): JsonResponse
    {
        $page = $list->handle(AuthenticatedUser::id($request), $request->validated('cursor'), (int) $request->validated('limit', 30));

        return response()->json([
            'data' => JournalEntryResource::collection($page['entries'])->resolve(),
            'nextCursor' => $page['nextCursor'],
        ]);
    }

    public function destroy(DeleteJournalEntryRequest $request, string $entryId, DeleteJournalEntry $delete): Response
    {
        $delete->handle(AuthenticatedUser::id($request), $entryId, (int) $request->validated('expectedVersion'));

        return response()->noContent();
    }

    public function show(JournalEntry $entry): JournalEntryResource
    {
        Gate::authorize('view', $entry);

        return new JournalEntryResource($entry);
    }

    public function save(SaveJournalDraftRequest $request, string $entryId, SaveJournalDraft $save): JournalEntryResource
    {
        return new JournalEntryResource($save->handle(AuthenticatedUser::id($request), $entryId, $request->validated()));
    }
}
