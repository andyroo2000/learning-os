<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\SaveJournalDraft;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\SaveJournalDraftRequest;
use App\Http\Resources\Journal\JournalEntryResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Support\Facades\Gate;

class JournalEntryController extends Controller
{
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
