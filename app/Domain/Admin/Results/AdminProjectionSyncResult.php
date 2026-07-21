<?php

namespace App\Domain\Admin\Results;

final readonly class AdminProjectionSyncResult
{
    public function __construct(
        public int $users,
        public int $inviteCodes,
    ) {}
}
