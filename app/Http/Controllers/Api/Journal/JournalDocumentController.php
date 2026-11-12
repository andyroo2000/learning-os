<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\ChangeJournalSuggestion;
use App\Domain\Journal\Actions\EditCorrectedJournal;
use App\Domain\Journal\Data\JournalDocumentInput;
use App\Domain\Journal\Data\JournalManualEditInput;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Resources\Journal\JournalAnalysisResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JournalDocumentController extends Controller
{
    public function suggestion(Request $request, JournalEntry $entry, ChangeJournalSuggestion $change): JsonResponse
    {
        Gate::authorize('view', $entry);
        $analysis = $change->handle(AuthenticatedUser::id($request), $entry->id, new JournalDocumentInput($request->all()));

        return response()->json(['data' => new JournalAnalysisResource($analysis)]);
    }

    public function edit(Request $request, JournalEntry $entry, EditCorrectedJournal $edit): JsonResponse
    {
        Gate::authorize('view', $entry);
        $analysis = $edit->handle(AuthenticatedUser::id($request), $entry->id, new JournalManualEditInput($request->all()));

        return response()->json(['data' => new JournalAnalysisResource($analysis)]);
    }
}
