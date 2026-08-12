<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Support\ContentGenerationRequestState;
use App\Domain\Content\Support\ContentGenerationRequestTerminalState;
use App\Domain\Content\Support\ContentSourceLock;
use Illuminate\Support\Facades\DB;

final class FinishContentGenerationDispatchAction
{
    public function succeeded(string $requestId, string $dispatchToken): bool
    {
        return DB::transaction(function () use ($dispatchToken, $requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $this->ownsClaim($request, $dispatchToken, allowTerminal: true)) {
                return false;
            }

            $request->dispatch_token = null;
            $request->dispatch_claimed_at = null;
            $request->dispatched_at ??= now();
            $request->save();

            return true;
        });
    }

    public function failed(string $requestId, string $dispatchToken, string $message): bool
    {
        return DB::transaction(function () use ($dispatchToken, $message, $requestId): bool {
            ContentSourceLock::acquireConvoLab(DB::connection());
            $request = ContentGenerationRequest::query()->whereKey($requestId)->lockForUpdate()->first();
            if (! $this->ownsClaim($request, $dispatchToken)) {
                return false;
            }

            $request->dispatch_token = null;
            $request->dispatch_claimed_at = null;
            ContentGenerationRequestTerminalState::fail(
                $request,
                503,
                'queue_unavailable',
                $message,
            );

            return true;
        });
    }

    private function ownsClaim(
        ?ContentGenerationRequest $request,
        string $dispatchToken,
        bool $allowTerminal = false,
    ): bool {
        return $request instanceof ContentGenerationRequest
            && ($allowTerminal || ! ContentGenerationRequestState::isTerminal($request->state))
            && is_string($request->dispatch_token)
            && hash_equals($request->dispatch_token, $dispatchToken);
    }
}
