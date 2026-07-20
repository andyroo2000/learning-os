<?php

namespace App\Domain\Media\Actions;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Results\AvatarAssetRedirect;
use App\Domain\Media\Support\StaticMediaPath;
use App\Domain\Media\Support\StaticMediaSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResolveAvatarAssetAction
{
    public function __construct(
        private readonly StaticMediaObjectStore $objectStore,
        private readonly StaticMediaSettings $settings,
    ) {}

    public function handle(string $avatarPath): ?AvatarAssetRedirect
    {
        if (! StaticMediaPath::isAvatar($avatarPath)) {
            return null;
        }

        if (! $this->settings->avatarSigningEnabled()) {
            return new AvatarAssetRedirect('/avatars/'.$avatarPath, false);
        }

        $objectPath = $this->settings->avatarObjectPath($avatarPath);

        try {
            if (! $this->objectStore->exists($objectPath)) {
                return null;
            }

            $location = $this->objectStore->signedReadUrl(
                $objectPath,
                Carbon::now()->addSeconds($this->settings->avatarTtlSeconds()),
                'image/jpeg',
            );

            return new AvatarAssetRedirect($location, true);
        } catch (Throwable $exception) {
            Log::warning('Avatar signed URL resolution failed.', [
                'avatar_path' => $avatarPath,
                'exception' => $exception,
            ]);

            $publicUrl = $this->settings->publicObjectUrl($objectPath);

            return $publicUrl !== null
                ? new AvatarAssetRedirect($publicUrl, false)
                : null;
        }
    }
}
