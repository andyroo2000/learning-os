<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\StartJournalReply;
use App\Domain\Journal\Actions\UndoJournalReply;
use App\Domain\Journal\Data\JournalReplyInput;
use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalEntry;
use App\Domain\Journal\Models\JournalReply;
use App\Http\Controllers\Controller;
use App\Http\Resources\Journal\JournalAnalysisResource;
use App\Http\Resources\Journal\JournalReplyResource;
use App\Http\Support\AuthenticatedUser;
use App\Jobs\AnalyzeJournalReply;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JournalReplyController extends Controller
{
    public function save(Request $request, JournalEntry $entry, string $replyId, StartJournalReply $start): JsonResponse
    {
        Gate::authorize('view', $entry);
        $input = new JournalReplyInput([...$request->all(), 'id' => $replyId]);
        JournalAnalysis::where('entry_id', $entry->id)->findOrFail($input->analysisId);
        $reply = $start->handle(AuthenticatedUser::id($request), $input);
        AnalyzeJournalReply::dispatch($reply->id);

        return response()->json(['data' => new JournalReplyResource($reply)], 202);
    }

    public function show(JournalEntry $entry, string $replyId): JsonResponse
    {
        Gate::authorize('view', $entry);
        $reply = JournalReply::whereHas('analysis', fn ($query) => $query->where('entry_id', $entry->id))
            ->findOrFail(CanonicalUlid::normalize($replyId));

        return response()->json(['data' => new JournalReplyResource($reply)]);
    }

    public function undo(Request $request, JournalEntry $entry, UndoJournalReply $undo): JsonResponse
    {
        Gate::authorize('view', $entry);
        $input = $request->validate([
            'analysisId' => ['required', 'ulid'],
            'expectedRevision' => ['required', 'integer', 'min:0', 'max:2147483646'],
        ]);
        $analysis = $undo->handle(AuthenticatedUser::id($request), $entry->id, $input);

        return response()->json(['data' => new JournalAnalysisResource($analysis)]);
    }
}
