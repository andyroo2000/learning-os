<?php

namespace App\Domain\Auth\Results;

use App\Domain\Admin\Models\AdminUserProjection;

final readonly class ResolveConvoLabGoogleIdentityResult
{
    public function __construct(
        public AdminUserProjection $account,
        public bool $requiresInvite,
        public bool $created,
    ) {}
}
