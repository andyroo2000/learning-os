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
        if (! extension_loaded('gd')) {
            throw new RuntimeException('The GD image extension is not available.');
        }
        if ($imageBytes === '' || strlen($imageBytes) > self::MAX_BYTES) {
            throw AdminMutationException::invalidAvatarImage();
        }

        $metadata = @getimagesizefromstring($imageBytes);
        $width = is_array($metadata) ? ($metadata[0] ?? null) : null;
        $height = is_array($metadata) ? ($metadata[1] ?? null) : null;
        $mediaType = is_array($metadata) ? ($metadata['mime'] ?? null) : null;
        if (! is_int($width) || ! is_int($height) || $width < 1 || $height < 1
            || ! is_string($mediaType) || ! isset(self::SUPPORTED_MEDIA_TYPES[$mediaType])
            || $width > intdiv(self::MAX_PIXELS, $height)) {
            throw AdminMutationException::invalidAvatarImage();
        }

        $left = (int) round(max(0, min($cropArea->x, $width - 1)));
        $top = (int) round(max(0, min($cropArea->y, $height - 1)));
        $cropWidth = (int) round(min($cropArea->width, $width - $left));
        $cropHeight = (int) round(min($cropArea->height, $height - $top));
        if ($cropWidth < 1 || $cropHeight < 1) {
            throw AdminMutationException::invalidAvatarCrop();
        }

        try {
            $cropped = (new ImageManager(new Driver))
                ->read($imageBytes)
                ->crop($cropWidth, $cropHeight, $left, $top)
                ->cover(self::OUTPUT_SIZE, self::OUTPUT_SIZE)
                ->toJpeg(85, progressive: true, strip: true);
        } catch (Throwable) {
            throw AdminMutationException::invalidAvatarImage();
        }

        return new ProcessedAdminAvatarImage(
            (string) $cropped,
            $mediaType,
            self::SUPPORTED_MEDIA_TYPES[$mediaType],
        );
    }
}
