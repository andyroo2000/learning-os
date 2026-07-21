<?php

namespace App\Domain\Content\Results;

use InvalidArgumentException;

final readonly class ContentCourseScriptUnit
{
    private const TYPES = ['marker', 'narration_L1', 'pause', 'L2'];

    private function __construct(
        public string $type,
        public ?string $label,
        public ?string $text,
        public ?string $reading,
        public ?string $translation,
        public ?string $voiceId,
        public ?float $speed,
        public ?float $seconds,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromProvider(array $input): self
    {
        $type = self::requiredString($input, 'type', 32);
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Course script unit type is invalid.');
        }
        self::assertExactKeys($input, match ($type) {
            'marker' => ['type', 'label'],
            'narration_L1' => ['type', 'text', 'voiceId'],
            'pause' => ['type', 'seconds'],
            'L2' => ['type', 'text', 'reading', 'translation', 'voiceId', 'speed'],
        });

        $unit = new self(
            type: $type,
            label: self::optionalString($input, 'label', 255),
            text: self::optionalString($input, 'text', 4000),
            reading: self::optionalString($input, 'reading', 4000),
            translation: self::optionalString($input, 'translation', 4000),
            voiceId: self::optionalString($input, 'voiceId', 255),
            speed: self::optionalNumber($input, 'speed'),
            seconds: self::optionalNumber($input, 'seconds'),
        );
        $unit->validateShape();

        return $unit;
    }

    /** @return array<string, float|string> */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type,
            'label' => $this->label,
            'text' => $this->text,
            'reading' => $this->reading,
            'translation' => $this->translation,
            'voiceId' => $this->voiceId,
            'speed' => $this->speed,
            'seconds' => $this->seconds,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function estimatedDurationSeconds(): float
    {
        if ($this->type === 'pause') {
            return $this->seconds ?? 0;
        }
        if ($this->type === 'narration_L1') {
            return max(1, mb_strlen($this->text ?? '') / 15);
        }
        if ($this->type === 'L2') {
            return max(1, mb_strlen($this->reading ?? $this->text ?? '') / (5 * ($this->speed ?? 1)));
        }

        return 0;
    }

    private function validateShape(): void
    {
        if ($this->type === 'marker' && $this->label === null) {
            throw new InvalidArgumentException('Course marker unit requires a label.');
        }
        if ($this->type === 'pause' && ($this->seconds === null || $this->seconds <= 0 || $this->seconds > 60)) {
            throw new InvalidArgumentException('Course pause unit duration is invalid.');
        }
        if (in_array($this->type, ['narration_L1', 'L2'], true)
            && ($this->text === null || $this->voiceId === null)) {
            throw new InvalidArgumentException('Course spoken unit requires text and a voice ID.');
        }
        if ($this->type === 'L2' && ($this->translation === null || $this->speed === null
            || $this->speed < 0.5 || $this->speed > 2)) {
            throw new InvalidArgumentException('Course target-language unit is invalid.');
        }
    }

    /** @param array<string, mixed> $input */
    private static function requiredString(array $input, string $key, int $max): string
    {
        $value = self::optionalString($input, $key, $max);
        if ($value === null) {
            throw new InvalidArgumentException("Course script unit {$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function optionalString(array $input, string $key, int $max): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (! is_string($input[$key])) {
            throw new InvalidArgumentException("Course script unit {$key} must be a string.");
        }
        $value = trim($input[$key]);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Course script unit {$key} is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function optionalNumber(array $input, string $key): ?float
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (! is_int($input[$key]) && ! is_float($input[$key])) {
            throw new InvalidArgumentException("Course script unit {$key} must be numeric.");
        }
        $value = (float) $input[$key];
        if (! is_finite($value)) {
            throw new InvalidArgumentException("Course script unit {$key} is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $input
     * @param  list<string>  $allowed
     */
    private static function assertExactKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new InvalidArgumentException('Course script unit contains unsupported fields.');
        }
    }
}
