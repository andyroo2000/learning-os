<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminSpeakerAvatar;
use Illuminate\Database\Eloquent\Collection;

class ListAdminSpeakerAvatarsAction
{
    /** @return Collection<int, AdminSpeakerAvatar> */
    public function handle(): Collection
    {
        return AdminSpeakerAvatar::query()
            ->orderBy('language')
            ->orderBy('gender')
            ->orderBy('tone')
            ->orderBy('id')
            ->get();
    }
}
