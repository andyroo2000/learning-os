<?php

namespace App\Domain\Admin\Data;

final readonly class StoredAdminAvatarObject
{
    public function __construct(
        public string $path,
        public string $url,
    ) {}
}
