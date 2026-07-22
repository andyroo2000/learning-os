<?php

namespace App\Domain\Admin\Support;

use App\Domain\Admin\Exceptions\AdminMutationException;

final readonly class AdminSpeakerAvatarFilename
{
    private const PATTERN = '/^ja-(male|female)-(casual|polite|formal)\.(jpg|jpeg|png|webp)$/';

    private function __construct(
        public string $value,
        public string $language,
        public string $gender,
        public string $tone,
    ) {}

    public static function from(string $filename): self
    {
        $normalized = strtolower(trim($filename));
        if (preg_match(self::PATTERN, $normalized, $matches) !== 1) {
            throw AdminMutationException::invalidAvatarFilename();
        }

        return new self($normalized, 'ja', $matches[1], $matches[2]);
    }
}
