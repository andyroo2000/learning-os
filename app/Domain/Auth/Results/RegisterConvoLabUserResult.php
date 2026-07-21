<?php

namespace App\Domain\Auth\Results;

use App\Domain\Admin\Models\AdminUserProjection;

final readonly class RegisterConvoLabUserResult
{
    public function __construct(
        public AdminUserProjection $account,
        public bool $created,
    ) {}
}
