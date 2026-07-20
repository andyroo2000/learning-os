<?php

namespace App\Domain\Media\Contracts;

use DateTimeInterface;

interface StaticMediaObjectStore
{
    public function exists(string $objectPath): bool;

    public function signedReadUrl(
        string $objectPath,
        DateTimeInterface $expiresAt,
        ?string $responseType = null,
    ): string;
}
