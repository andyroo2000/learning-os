<?php

namespace App\Http\Controllers\Api\Sync;

use App\Domain\Sync\Actions\ListSyncFeedEntriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\ListSyncFeedEntriesRequest;
use App\Http\Resources\Sync\SyncFeedEntryResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ListSyncFeedEntriesController extends Controller
{
    public function __invoke(
        ListSyncFeedEntriesRequest $request,
        ListSyncFeedEntriesAction $listSyncFeedEntries,
    ): AnonymousResourceCollection {
        /** @var User $user */
        $user = $request->user();

        $afterCheckpoint = $request->afterCheckpoint();
        $domain = $request->domain();
        $resourceType = $request->resourceType();
        $pageSize = $request->pageSize();

        $result = $listSyncFeedEntries->handle(
            userId: $user->id,
            afterCheckpoint: $afterCheckpoint,
            domain: $domain,
            resourceType: $resourceType,
            pageSize: $pageSize,
        );

        return SyncFeedEntryResource::collection($result->entries)
            ->additional([
                'meta' => [
                    'after_checkpoint' => $afterCheckpoint,
                    'current_checkpoint' => $result->currentCheckpoint,
                    'domain' => $domain,
                    'resource_type' => $resourceType,
                    // Filtered complete pages can advance to the full-feed high-water mark without skipping future entries.
                    'next_checkpoint' => $result->nextCheckpoint($afterCheckpoint),
                    'has_more' => $result->hasMore,
                    'per_page' => $pageSize->value(),
                ],
            ]);
    }
}
