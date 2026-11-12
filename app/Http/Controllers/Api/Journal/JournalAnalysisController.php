<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\StartJournalAnalysis;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Journal\SubmitJournalEntryRequest;
use App\Http\Resources\Journal\JournalAnalysisResource;
use App\Http\Resources\Journal\JournalEntryResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\AnalyzeJournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class JournalAnalysisController extends Controller
{
    public function submit(SubmitJournalEntryRequest $request, string $entryId, StartJournalAnalysis $start): JsonResponse
    {
        $analysis = $start->handle(AuthenticatedUser::id($request), $entryId, (int) $request->validated('expectedVersion'));
        AnalyzeJournalEntry::dispatch($analysis->id);

        return response()->json(['data' => new JournalAnalysisResource($analysis)], 202);
    }

    public function show(JournalEntry $entry): JsonResponse
    {
        Gate::authorize('view', $entry);
        $analysis = JournalAnalysis::where('entry_id', $entry->id)->orderByDesc('source_version')->first();
        // Ready feedback and its generated title commit together. Route binding may predate that commit.
        $entry->refresh();
        if ($analysis?->source_body !== $entry->body) {
            $analysis = null;
        }

        return response()->json([
            'data' => $analysis === null ? null : new JournalAnalysisResource($analysis),
            'entry' => new JournalEntryResource($entry),
        ]);
    }
}
