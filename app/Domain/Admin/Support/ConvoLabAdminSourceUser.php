<?php

namespace App\Domain\Admin\Support;

use stdClass;

final readonly class ConvoLabAdminSourceUser
{
    public function __construct(
        public stdClass $row,
        public string $id,
        public string $email,
    ) {}
}
