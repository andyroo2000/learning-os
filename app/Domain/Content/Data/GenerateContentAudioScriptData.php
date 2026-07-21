<?php

namespace App\Domain\Content\Data;

use InvalidArgumentException;

final readonly class GenerateContentAudioScriptData
{
    private function __construct(
        public string $kind,
        public bool $force,
    ) {}

    /** @param array<string, mixed> $input */
    public static function render(array $input = []): self
    {
        if ($input !== []) {
            throw new InvalidArgumentException('Script render input must be empty.');
        }

        return new self('render', false);
    }

    /** @param array<string, mixed> $input */
    public static function images(array $input): self
    {
        $force = $input['force'] ?? false;
        if (! is_bool($force)) {
            throw new InvalidArgumentException('Script image force must be boolean.');
        }

        return new self('images', $force);
    }

    /** @param array<string, mixed> $input */
    public static function fromJob(string $kind, array $input): self
    {
        return match ($kind) {
            'render' => self::render($input),
            'images' => self::images($input),
            default => throw new InvalidArgumentException('Script generation kind is invalid.'),
        };
    }

    /** @return array{force?: bool} */
    public function toArray(): array
    {
        return $this->kind === 'images' ? ['force' => $this->force] : [];
    }
}
