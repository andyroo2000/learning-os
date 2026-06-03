<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Sync\Data\RecordSyncFeedEntryData;
use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use InvalidArgumentException;
use LogicException;

class RecordSyncFeedEntryAction
{
    public function handle(RecordSyncFeedEntryData $data): SyncFeedEntry
    {
        // userId comes from authenticated/internal context; invalid IDs are caller bugs.
        if ($data->userId < 1) {
            throw new LogicException('Sync feed entry user ID must be a positive integer.');
        }

        $this->assertStringColumn('domain', $data->domain, SyncFeedEntry::MAX_DOMAIN_LENGTH);
        $this->assertStringColumn('resource_type', $data->resourceType, SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        $this->assertStringColumn('resource_id', $data->resourceId, SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);

        $operation = SyncFeedOperation::tryFrom($data->operation);

        if ($operation === null) {
            throw new InvalidArgumentException('Sync feed operation must be one of: '.implode(', ', SyncFeedOperation::values()).'.');
        }

        $entry = new SyncFeedEntry([
            'user_id' => $data->userId,
            'domain' => $data->domain,
            'resource_type' => $data->resourceType,
            'resource_id' => $data->resourceId,
            'operation' => $operation,
            'server_recorded_at' => now(),
            'payload' => $data->payload,
        ]);

        $entry->save();

        return $entry;
    }

    private function assertStringColumn(string $field, string $value, int $maxLength): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("Sync feed {$field} is required.");
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Sync feed {$field} must not exceed {$maxLength} characters.");
        }
    }
}
