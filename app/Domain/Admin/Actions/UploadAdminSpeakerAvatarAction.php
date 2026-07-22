<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Admin\Services\AdminAvatarObjectStorage;
use App\Domain\Admin\Support\AdminSpeakerAvatarFilename;
use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class UploadAdminSpeakerAvatarAction
{
    public function __construct(
        private readonly AdminAvatarImageProcessor $imageProcessor,
        private readonly AdminAvatarObjectStorage $storage,
    ) {}

    public function handle(
        string $filename,
        string $imageBytes,
        AdminAvatarCropArea $cropArea,
    ): AdminSpeakerAvatar {
        $avatarFilename = AdminSpeakerAvatarFilename::from($filename);
        $processed = $this->imageProcessor->process($imageBytes, $cropArea);
        $stored = [];

        try {
            $cropped = $this->storage->putSpeakerCropped($avatarFilename->value, $processed->croppedJpeg);
            $stored[] = $cropped;
            $original = $this->storage->putSpeakerOriginal(
                $avatarFilename->value,
                $processed->originalExtension,
                $imageBytes,
                $processed->originalMediaType,
            );
            $stored[] = $original;

            $now = now();
            DB::table('admin_speaker_avatars')->upsert([[
                'id' => (string) Str::uuid(),
                'filename' => $avatarFilename->value,
                'cropped_url' => $cropped->url,
                'original_url' => $original->url,
                'language' => $avatarFilename->language,
                'gender' => $avatarFilename->gender,
                'tone' => $avatarFilename->tone,
                'source_system' => ConvoLabAccountSource::LEARNING_OS,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['filename'], [
                'cropped_url',
                'original_url',
                'language',
                'gender',
                'tone',
                'source_system',
                'updated_at',
            ]);
        } catch (Throwable $exception) {
            $this->storage->deleteQuietly($stored);

            throw $exception;
        }

        return AdminSpeakerAvatar::query()->where('filename', $avatarFilename->value)->firstOrFail();
    }
}
