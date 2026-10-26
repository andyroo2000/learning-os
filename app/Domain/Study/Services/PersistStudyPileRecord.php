<?php

namespace App\Domain\Study\Services;

use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Models\StudyPileChapter;
use App\Domain\Study\Sync\StudyPilePayload;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use Illuminate\Support\Facades\Validator;

final class PersistStudyPileRecord
{
    public function __construct(private readonly RecordSyncFeedEntryAction $recordSync) {}

    /** Called inside the owning-user transaction; feed failure rolls back the catalog write. */
    public function handle(int $userId, StudyPile|StudyPileChapter $record, array $attributes): void
    {
        if (! $record->exists) {
            Validator::make($attributes, ['title' => ['required']])->validate();
        }
        foreach ($attributes as $key => $value) {
            $record->{$key} = $value;
        }
        if (! $record->isDirty()) {
            return;
        }

        $operation = $record->exists ? 'update' : 'create';
        $record->saveOrFail();
        $this->recordSync->handle(RecordSyncFeedEntryData::fromInput(
            userId: $userId,
            domain: 'study',
            resourceType: $record instanceof StudyPile ? 'pile' : 'pile_chapter',
            resourceId: $record->id,
            operation: $operation,
            payload: StudyPilePayload::record($record),
        ));
    }
}
