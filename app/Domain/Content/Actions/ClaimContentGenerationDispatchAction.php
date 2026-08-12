<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimContentGenerationDispatchAction
{
    public function handle(string $requestId): ?string
    {
        return DB::transaction(function () use ($requestId): ?string {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $request instanceof ContentGenerationRequest
                || $request->job_id === null
                || $request->dispatched_at !== null
                || ContentGenerationRequestState::isTerminal($request->state)) {
                return null;
            }

            if ($request->dispatch_token !== null
                && $request->dispatch_claimed_at !== null
                && $request->dispatch_claimed_at->isAfter(
                    now()->subSeconds(ContentGenerationRequestState::DISPATCH_CLAIM_STALE_SECONDS),
                )) {
                return null;
            }

            $token = (string) Str::uuid();
            $request->dispatch_token = $token;
            $request->dispatch_claimed_at = now();
            $request->save();

            return $token;
        });
    }
}
