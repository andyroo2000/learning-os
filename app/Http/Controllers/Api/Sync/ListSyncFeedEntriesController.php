<?php

namespace App\Http\Controllers\Api\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Domain\Sync\Exceptions\StaleSyncFeedCheckpointException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\ListSyncFeedEntriesRequest;
use App\Http\Resources\Sync\SyncFeedEntryResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListSyncFeedEntriesController extends Controller
{
    public function __invoke(
        ListSyncFeedEntriesRequest $request,
        ListSyncFeedEntriesAction $listSyncFeedEntries,
    ): AnonymousResourceCollection|JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $afterCheckpoint = $request->afterCheckpoint();
        $domain = $request->domain();
        $resourceType = $request->resourceType();
        $resourceId = $request->resourceId();
        $operation = $request->operation();
        $pageSize = $request->pageSize();

        try {
            $result = $listSyncFeedEntries->handle(
                userId: $user->id,
                afterCheckpoint: $afterCheckpoint,
                domain: $domain,
                resourceType: $resourceType,
                resourceId: $resourceId,
                operation: $operation,
                pageSize: $pageSize,
            );
        } catch (StaleSyncFeedCheckpointException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
                'meta' => [
                    'after_checkpoint' => $exception->afterCheckpoint(),
                    'oldest_available_checkpoint' => $exception->oldestAvailableCheckpoint(),
                    'domain' => $exception->domain(),
                    'resource_type' => $exception->resourceType(),
                    'resource_id' => $exception->resourceId(),
                    'operation' => $exception->operation(),
                    'required_action' => $exception->requiredAction(),
                ],
            ], 409);
        }

        return SyncFeedEntryResource::collection($result->entries)
            ->additional([
                'meta' => [
                    'after_checkpoint' => $afterCheckpoint,
                    'current_checkpoint' => $result->currentCheckpoint,
                    'domain' => $domain,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'operation' => $operation,
                    // Filtered complete pages can advance to the full-feed high-water mark without skipping future entries.
                    'next_checkpoint' => $result->nextCheckpoint($afterCheckpoint),
                    'has_more' => $result->hasMore,
                    'per_page' => $pageSize->value(),
                ],
            ]);
    }
}
