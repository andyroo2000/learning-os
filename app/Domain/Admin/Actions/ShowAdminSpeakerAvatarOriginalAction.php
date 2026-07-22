<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Admin\Support\AdminSpeakerAvatarFilename;

class ShowAdminSpeakerAvatarOriginalAction
{
    public function handle(string $filename): string
    {
        $avatarFilename = AdminSpeakerAvatarFilename::from($filename);

        $originalUrl = AdminSpeakerAvatar::query()
            ->where('filename', $avatarFilename->value)
            ->value('original_url');

        if (! is_string($originalUrl)) {
            throw AdminMutationException::speakerAvatarNotFound();
        }

        return $originalUrl;
    }
}
