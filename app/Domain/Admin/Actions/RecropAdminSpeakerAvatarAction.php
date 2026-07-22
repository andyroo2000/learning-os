<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Admin\Services\AdminAvatarObjectStorage;
use App\Domain\Admin\Support\AdminSpeakerAvatarFilename;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecropAdminSpeakerAvatarAction
{
    public function __construct(
        private readonly AdminAvatarImageProcessor $imageProcessor,
        private readonly AdminAvatarObjectStorage $storage,
    ) {}

    public function handle(string $filename, AdminAvatarCropArea $cropArea): AdminSpeakerAvatar
    {
        $avatarFilename = AdminSpeakerAvatarFilename::from($filename);
        $avatar = AdminSpeakerAvatar::query()->where('filename', $avatarFilename->value)->first();
        if ($avatar === null) {
            throw AdminMutationException::speakerAvatarNotFound();
        }

        $originalUrl = $avatar->original_url;
        $processed = $this->imageProcessor->process(
            $this->storage->readPublicAvatar($originalUrl),
            $cropArea,
        );
        $cropped = $this->storage->putSpeakerCropped($avatarFilename->value, $processed->croppedJpeg);

        try {
            DB::transaction(function () use ($avatarFilename, $originalUrl, $cropped): void {
                $locked = AdminSpeakerAvatar::query()
                    ->where('filename', $avatarFilename->value)
                    ->lockForUpdate()
                    ->first();
                if ($locked === null) {
                    throw AdminMutationException::speakerAvatarNotFound();
                }
                if (! hash_equals($originalUrl, $locked->original_url)) {
                    throw AdminMutationException::speakerAvatarChanged();
                }

                $locked->cropped_url = $cropped->url;
                $locked->source_system = ConvoLabAccountSource::LEARNING_OS;
                $locked->save();
            });
        } catch (Throwable $exception) {
            $this->storage->deleteQuietly([$cropped]);

            throw $exception;
        }

        return AdminSpeakerAvatar::query()->where('filename', $avatarFilename->value)->firstOrFail();
    }
}
