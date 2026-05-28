<?php

namespace App\Domain\Media\Exceptions;

use DomainException;

class CannotAttachMediaToCard extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function invalidCardId(): self
    {
        return new self('Card ID must be a valid ULID.', 'card_id');
    }

    public static function invalidMediaAssetId(): self
    {
        return new self('Media asset ID must be a valid ULID.', 'media_asset_id');
    }

    public static function missingCard(): self
    {
        return new self('Card does not exist.', 'card_id');
    }

    public static function missingMediaAsset(): self
    {
        return new self('Media asset does not exist.', 'media_asset_id');
    }

    public function field(): string
    {
        return $this->field;
    }
}
