<?php

namespace App\Domain\Admin\Exceptions;

use RuntimeException;

final class AdminMutationException extends RuntimeException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function selfDeletion(): self
    {
        return new self('Cannot delete your own account', 400);
    }

    public static function userNotFound(): self
    {
        return new self('User not found', 404);
    }

    public static function adminDeletion(): self
    {
        return new self('Cannot delete admin users', 403);
    }

    public static function duplicateInvite(): self
    {
        return new self('This code already exists', 400);
    }

    public static function inviteGenerationFailed(): self
    {
        return new self('Unable to generate invite code', 503);
    }

    public static function inviteNotFound(): self
    {
        return new self('Invite code not found', 404);
    }

    public static function usedInvite(): self
    {
        return new self('Cannot delete used invite codes', 400);
    }

    public static function invalidPronunciationDictionary(string $message): self
    {
        return new self($message, 400);
    }

    public static function invalidAvatarFilename(): self
    {
        return new self('Invalid avatar filename format', 400);
    }

    public static function speakerAvatarNotFound(): self
    {
        return new self('Speaker avatar not found', 404);
    }

    public static function invalidAvatarCrop(): self
    {
        return new self('Invalid crop area', 400);
    }

    public static function invalidAvatarImage(): self
    {
        return new self('Invalid image file', 400);
    }

    public static function missingAvatarImage(): self
    {
        return new self('No image file provided', 400);
    }

    public static function speakerAvatarChanged(): self
    {
        return new self('Speaker avatar changed while it was being re-cropped', 409);
    }

    public static function speakerAvatarRequiresUpload(): self
    {
        return new self('Speaker avatar must be uploaded before it can be re-cropped', 409);
    }

    public function status(): int
    {
        return $this->status;
    }
}
