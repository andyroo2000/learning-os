<?php

namespace App\Domain\Admin\Data;

final readonly class ProcessedAdminAvatarImage
{
    public function __construct(
        public string $croppedJpeg,
        public string $originalMediaType,
        public string $originalExtension,
    ) {}
}
