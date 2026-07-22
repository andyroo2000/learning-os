<?php

namespace App\Domain\Admin\Data;

use App\Domain\Admin\Exceptions\AdminMutationException;

final readonly class AdminAvatarCropArea
{
    private function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    public static function from(mixed $value): self
    {
        if (! is_array($value) || array_is_list($value)) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        $values = [];
        foreach (['x', 'y', 'width', 'height'] as $field) {
            $fieldValue = $value[$field] ?? null;
            if (! is_int($fieldValue) && ! is_float($fieldValue)) {
                throw AdminMutationException::invalidAvatarCrop();
            }

            $normalized = (float) $fieldValue;
            if (! is_finite($normalized)) {
                throw AdminMutationException::invalidAvatarCrop();
            }
            $values[$field] = $normalized;
        }

        if ($values['width'] <= 0 || $values['height'] <= 0) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        return new self($values['x'], $values['y'], $values['width'], $values['height']);
    }
}
