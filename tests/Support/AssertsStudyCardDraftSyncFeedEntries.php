<?php

namespace Tests\Support;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Carbon\CarbonInterface;

trait AssertsStudyCardDraftSyncFeedEntries
{
    protected function assertStudyCardDraftSyncPayloadRecorded(
        StudyCardDraft $draft,
        SyncFeedOperation $operation,
        ?CarbonInterface $deletedAt = null,
        ?int $afterCheckpoint = null,
    ): SyncFeedEntry {
        $entries = SyncFeedEntry::query()
            ->where('domain', StudyCardDraftSyncPayload::DOMAIN)
            ->where('resource_type', StudyCardDraftSyncPayload::RESOURCE_TYPE)
            ->where('resource_id', $draft->id)
            ->where('operation', $operation->value)
            ->when($afterCheckpoint !== null, fn ($query) => $query->where('checkpoint', '>', $afterCheckpoint))
            ->get();

        $this->assertCount(
            1,
            $entries,
            "Expected exactly one sync feed entry for study card draft {$draft->id} with operation {$operation->value}."
                .($afterCheckpoint === null ? '' : " after checkpoint {$afterCheckpoint}."),
        );

        $entry = $entries->first();

        $this->assertSame($draft->user_id, $entry->user_id);
        $this->assertEquals(StudyCardDraftSyncPayload::fromDraft($draft, $deletedAt), $entry->payload);

        return $entry;
    }
}
