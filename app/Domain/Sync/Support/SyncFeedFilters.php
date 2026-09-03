<?php

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Domain\Sync\Models\SyncFeedEntry;
use InvalidArgumentException;

final class SyncFeedFilters
{
    private function __construct() {}

    /** @return array{?string, ?string, ?string, ?string} */
    public static function fromInput(
        ?string $domain,
        ?string $resourceType,
        ?string $resourceId,
        ?string $operation,
    ): array {
        // Direct callers skip HTTP request normalization, so keep this domain boundary canonical.
        $domain = self::normalizedFilter($domain);
        $resourceType = self::normalizedFilter($resourceType);
        $resourceId = self::normalizedFilter($resourceId);
        $operation = self::normalizedFilter($operation);

        self::assertFilterIsNotBlank('domain', $domain);
        self::assertFilterIsNotBlank('resource_type', $resourceType);
        self::assertFilterIsNotBlank('resource_id', $resourceId);
        self::assertFilterLength('domain', $domain, SyncFeedEntry::MAX_DOMAIN_LENGTH);
        self::assertFilterLength('resource_type', $resourceType, SyncFeedEntry::MAX_RESOURCE_TYPE_LENGTH);
        self::assertFilterLength('resource_id', $resourceId, SyncFeedEntry::MAX_RESOURCE_ID_LENGTH);
        self::assertValidOperation($operation);
        self::assertCompleteResourceIdentity($domain, $resourceType, $resourceId);

        return [$domain, $resourceType, $resourceId, $operation];
    }

    private static function normalizedFilter(?string $value): ?string
    {
        return $value === null ? null : SyncFeedMetadata::normalize($value);
    }

    private static function assertFilterIsNotBlank(string $field, ?string $value): void
    {
        if ($value === '') {
            throw new InvalidArgumentException("Sync feed {$field} must not be blank when provided.");
        }
    }

    private static function assertFilterLength(string $field, ?string $value, int $maxLength): void
    {
        if ($value !== null && mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Sync feed {$field} must not exceed {$maxLength} characters.");
        }
    }

    private static function assertValidOperation(?string $operation): void
    {
        if ($operation === '') {
            throw new InvalidArgumentException('Sync feed operation must not be blank when provided.');
        }

        if ($operation !== null && SyncFeedOperation::tryFrom($operation) === null) {
            throw new InvalidArgumentException('Sync feed operation must be one of: '.implode(', ', SyncFeedOperation::values()).'.');
        }
    }

    private static function assertCompleteResourceIdentity(
        ?string $domain,
        ?string $resourceType,
        ?string $resourceId,
    ): void {
        if ($resourceId === null) {
            return;
        }

        if ($domain === null || $resourceType === null) {
            throw new InvalidArgumentException('Sync feed resource_id filters require both domain and resource_type.');
        }
    }
}
