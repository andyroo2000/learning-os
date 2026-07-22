<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Data\StoredAdminAvatarObject;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Media\Contracts\StaticMediaObjectWriter;
use App\Domain\Media\Support\StaticMediaSettings;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class AdminAvatarObjectStorage
{
    public function __construct(
        private readonly StaticMediaObjectWriter $writer,
        private readonly StaticMediaSettings $settings,
    ) {}

    public function putSpeakerCropped(string $filename, string $jpeg): StoredAdminAvatarObject
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $this->put("speakers/{$this->uniqueName("{$baseName}.jpg")}", $jpeg, 'image/jpeg');
    }

    public function putSpeakerOriginal(
        string $filename,
        string $extension,
        string $contents,
        string $contentType,
    ): StoredAdminAvatarObject {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);

        return $this->put(
            "speakers/{$this->uniqueName("original-{$baseName}.{$extension}")}",
            $contents,
            $contentType,
        );
    }

    public function putUser(string $convoLabUserId, string $jpeg): StoredAdminAvatarObject
    {
        return $this->put($this->uniqueName("user-{$convoLabUserId}.jpg"), $jpeg, 'image/jpeg');
    }

    public function readPublicAvatar(string $url): string
    {
        $path = $this->settings->publicObjectPath($url);
        if ($path === null) {
            throw AdminMutationException::speakerAvatarRequiresUpload();
        }

        return $this->writer->read($path);
    }

    /** @param list<StoredAdminAvatarObject> $objects */
    public function deleteQuietly(array $objects): void
    {
        foreach ($objects as $object) {
            $this->deletePathQuietly($object->path);
        }
    }

    private function put(string $relativePath, string $contents, string $contentType): StoredAdminAvatarObject
    {
        $path = $this->settings->avatarObjectPath($relativePath);
        $url = $this->settings->publicObjectUrl($path);
        if ($url === null) {
            throw new RuntimeException('GCS_BUCKET_NAME is not configured.');
        }

        try {
            $this->writer->putPublic($path, $contents, $contentType);
        } catch (Throwable $exception) {
            $this->deletePathQuietly($path);

            throw $exception;
        }

        return new StoredAdminAvatarObject($path, $url);
    }

    private function deletePathQuietly(string $path): void
    {
        try {
            $this->writer->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function uniqueName(string $filename): string
    {
        return strtolower((string) Str::uuid()).'-'.$filename;
    }
}
