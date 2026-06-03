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
        $pageSize = $request->pageSize();
        $entries = $listSyncFeedEntries->handle(
            userId: $user->id,
            afterCheckpoint: $afterCheckpoint,
            domain: $request->domain(),
            pageSize: $pageSize,
        );

        return SyncFeedEntryResource::collection($entries)
            ->additional([
                'meta' => [
                    'after_checkpoint' => $afterCheckpoint,
                    // Domain-filtered clients should keep a separate bookmark from the full-feed cursor.
                    'next_checkpoint' => $entries->max('checkpoint') ?? $afterCheckpoint,
                    'per_page' => $pageSize->value(),
                ],
            ]);
    }
}
