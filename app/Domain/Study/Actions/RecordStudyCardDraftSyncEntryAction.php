<?php

namespace App\Domain\Study\Actions;

use App\Domain\Study\Models\StudyCardDraft;
use App\Domain\Study\Sync\StudyCardDraftSyncPayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use Carbon\CarbonInterface;

class RecordStudyCardDraftSyncEntryAction
{
    public function __construct(
        private readonly RecordSyncFeedEntryAction $recordSyncFeedEntry,
    ) {}

    public function handle(
        StudyCardDraft $draft,
        SyncFeedOperation $operation,
        ?CarbonInterface $deletedAt = null,
    ): SyncFeedEntry {
        return $this->recordSyncFeedEntry->handle(
            RecordSyncFeedEntryData::fromInput(
                userId: $draft->user_id,
                domain: StudyCardDraftSyncPayload::DOMAIN,
                resourceType: StudyCardDraftSyncPayload::RESOURCE_TYPE,
                resourceId: $draft->id,
                operation: $operation->value,
                payload: StudyCardDraftSyncPayload::fromDraft($draft, $deletedAt),
            ),
        );
    }
}
