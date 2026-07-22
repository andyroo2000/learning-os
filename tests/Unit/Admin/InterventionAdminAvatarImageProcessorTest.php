<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Services\InterventionAdminAvatarImageProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InterventionAdminAvatarImageProcessorTest extends TestCase
{
    public function test_it_clamps_crops_and_outputs_a_256_pixel_jpeg(): void
    {
        $image = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);
        $this->assertIsString($source);

        $result = (new InterventionAdminAvatarImageProcessor)->process(
            $source,
            AdminAvatarCropArea::from(['x' => -5, 'y' => -5, 'width' => 20, 'height' => 20]),
        );

        $metadata = getimagesizefromstring($result->croppedJpeg);
        $this->assertIsArray($metadata);
        $this->assertSame([256, 256], [$metadata[0], $metadata[1]]);
        $this->assertSame('image/jpeg', $metadata['mime']);
        $this->assertSame('image/png', $result->originalMediaType);
        $this->assertSame('png', $result->originalExtension);
    }

    #[DataProvider('invalidImageProvider')]
    public function test_it_rejects_invalid_or_oversized_images_before_encoding(string $image): void
    {
        $this->expectException(AdminMutationException::class);
        $this->expectExceptionMessage('Invalid image file');

        (new InterventionAdminAvatarImageProcessor)->process(
            $image,
            AdminAvatarCropArea::from(['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1]),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidImageProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'not an image' => ['not-an-image'];
        yield 'over byte limit' => [str_repeat('x', InterventionAdminAvatarImageProcessor::MAX_BYTES + 1)];

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFElEQVR4nGP8z8DAwMDAxMDAwMAAAAwBAQDJ/pLvAAAAAElFTkSuQmCC',
            true,
        );
        if (is_string($png)) {
            $oversizedDimensions = substr_replace($png, pack('N', 50_000), 16, 4);
            $oversizedDimensions = substr_replace($oversizedDimensions, pack('N', 50_000), 20, 4);
            yield 'over pixel limit' => [$oversizedDimensions];
        }
    }
}
