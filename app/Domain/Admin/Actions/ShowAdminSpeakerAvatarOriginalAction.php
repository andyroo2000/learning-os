<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSpeakerAvatar;

class ShowAdminSpeakerAvatarOriginalAction
{
    public function handle(string $filename): string
    {
        if (preg_match('/^ja-(male|female)-(casual|polite|formal)\.(jpg|jpeg|png|webp)$/i', $filename) !== 1) {
            throw AdminMutationException::invalidAvatarFilename();
        }

        $originalUrl = AdminSpeakerAvatar::query()
            ->where('filename', strtolower($filename))
            ->value('original_url');

        if (! is_string($originalUrl)) {
            throw AdminMutationException::speakerAvatarNotFound();
        }

        return $originalUrl;
    }
}
