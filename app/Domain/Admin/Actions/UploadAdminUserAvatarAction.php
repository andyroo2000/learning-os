<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminUserProjection;
use App\Domain\Admin\Services\AdminAvatarObjectStorage;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use App\Domain\Content\Support\ConvoLabUserId;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UploadAdminUserAvatarAction
{
    public function __construct(
        private readonly AdminAvatarImageProcessor $imageProcessor,
        private readonly AdminAvatarObjectStorage $storage,
    ) {}

    public function handle(
        string $convoLabUserId,
        string $imageBytes,
        AdminAvatarCropArea $cropArea,
    ): string {
        $normalizedUserId = ConvoLabUserId::normalizeOrNull($convoLabUserId);
        if ($normalizedUserId === null
            || ! AdminUserProjection::query()->whereKey($normalizedUserId)->exists()) {
            throw AdminMutationException::userNotFound();
        }

        $processed = $this->imageProcessor->process($imageBytes, $cropArea);
        $stored = $this->storage->putUser($normalizedUserId, $processed->croppedJpeg);

        try {
            DB::transaction(function () use ($normalizedUserId, $stored): void {
                $projection = AdminUserProjection::query()->lockForUpdate()->find($normalizedUserId);
                if ($projection === null) {
                    throw AdminMutationException::userNotFound();
                }

                $projection->avatar_url = $stored->url;
                $projection->avatar_source_system = ConvoLabAccountSource::LEARNING_OS;
                $projection->updated_at = now();
                $projection->save();
            });
        } catch (Throwable $exception) {
            $this->storage->deleteQuietly([$stored]);

            throw $exception;
        }

        return $stored->url;
    }
}
