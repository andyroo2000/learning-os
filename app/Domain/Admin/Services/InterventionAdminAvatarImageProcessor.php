<?php

namespace App\Domain\Admin\Services;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Data\ProcessedAdminAvatarImage;
use App\Domain\Admin\Exceptions\AdminMutationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

final class InterventionAdminAvatarImageProcessor implements AdminAvatarImageProcessor
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PIXELS = 40_000_000;

    public const OUTPUT_SIZE = 256;

    /** @var array<string, string> */
    private const SUPPORTED_MEDIA_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function process(string $imageBytes, AdminAvatarCropArea $cropArea): ProcessedAdminAvatarImage
    {
        self::ensureGdIsAvailable();
        self::validateByteCount($imageBytes);

        $metadata = self::imageMetadata($imageBytes);
        $crop = self::cropDimensions($cropArea, $metadata['width'], $metadata['height']);

        return new ProcessedAdminAvatarImage(
            self::cropImage($imageBytes, $crop),
            $metadata['mediaType'],
            self::SUPPORTED_MEDIA_TYPES[$metadata['mediaType']],
        );
    }

    private static function ensureGdIsAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD image extension is not available.');
        }
    }

    private static function validateByteCount(string $imageBytes): void
    {
        if ($imageBytes === '') {
            throw AdminMutationException::invalidAvatarImage();
        }
        if (strlen($imageBytes) > self::MAX_BYTES) {
            throw AdminMutationException::invalidAvatarImage();
        }
    }

    /** @return array{width: int, height: int, mediaType: string} */
    private static function imageMetadata(string $imageBytes): array
    {
        $metadata = @getimagesizefromstring($imageBytes);
        if (! is_array($metadata)) {
            throw AdminMutationException::invalidAvatarImage();
        }

        $width = self::positiveDimension($metadata[0] ?? null);
        $height = self::positiveDimension($metadata[1] ?? null);
        $mediaType = $metadata['mime'] ?? null;
        if (! is_string($mediaType) || ! isset(self::SUPPORTED_MEDIA_TYPES[$mediaType])) {
            throw AdminMutationException::invalidAvatarImage();
        }
        self::validatePixelCount($width, $height);

        return ['width' => $width, 'height' => $height, 'mediaType' => $mediaType];
    }

    private static function positiveDimension(mixed $value): int
    {
        if (! is_int($value)) {
            throw AdminMutationException::invalidAvatarImage();
        }
        if ($value < 1) {
            throw AdminMutationException::invalidAvatarImage();
        }

        return $value;
    }

    private static function validatePixelCount(int $width, int $height): void
    {
        if ($width > intdiv(self::MAX_PIXELS, $height)) {
            throw AdminMutationException::invalidAvatarImage();
        }
    }

    /** @return array{left: int, top: int, width: int, height: int} */
    private static function cropDimensions(AdminAvatarCropArea $cropArea, int $width, int $height): array
    {
        $left = (int) round(max(0, min($cropArea->x, $width - 1)));
        $top = (int) round(max(0, min($cropArea->y, $height - 1)));
        $cropWidth = (int) round(min($cropArea->width, $width - $left));
        $cropHeight = (int) round(min($cropArea->height, $height - $top));
        if ($cropWidth < 1) {
            throw AdminMutationException::invalidAvatarCrop();
        }
        if ($cropHeight < 1) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        return ['left' => $left, 'top' => $top, 'width' => $cropWidth, 'height' => $cropHeight];
    }

    /** @param array{left: int, top: int, width: int, height: int} $crop */
    private static function cropImage(string $imageBytes, array $crop): string
    {
        try {
            $cropped = (new ImageManager(new Driver))
                ->read($imageBytes)
                ->crop($crop['width'], $crop['height'], $crop['left'], $crop['top'])
                ->cover(self::OUTPUT_SIZE, self::OUTPUT_SIZE)
                ->toJpeg(85, progressive: true, strip: true);
        } catch (Throwable) {
            throw AdminMutationException::invalidAvatarImage();
        }

        return (string) $cropped;
    }
}
