<?php

namespace App\Policies;

use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MediaAssetPolicy
{
    public function view(User $user, MediaAsset $mediaAsset): Response
    {
        return $mediaAsset->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
