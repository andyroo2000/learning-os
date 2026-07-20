<?php

namespace App\Domain\Media\Results;

final readonly class AvatarAssetRedirect
{
    public function __construct(
        public string $location,
        public bool $cachePrivately,
    ) {}
}
