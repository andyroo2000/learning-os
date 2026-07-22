<?php

namespace App\Domain\Admin\Contracts;

use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Data\ProcessedAdminAvatarImage;

interface AdminAvatarImageProcessor
{
    public function process(string $imageBytes, AdminAvatarCropArea $cropArea): ProcessedAdminAvatarImage;
}
