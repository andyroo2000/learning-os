<?php

namespace Database\Factories;

use App\Domain\Media\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = fake()->randomElement(['jpg', 'mp3', 'mp4', 'png', 'webp']);
        $filename = fake()->uuid().'.'.$extension;

        return [
            'disk' => 'media',
            'path' => 'uploads/'.$filename,
            'public_url' => null,
            'mime_type' => fake()->randomElement([
                'audio/mpeg',
                'image/jpeg',
                'image/png',
                'image/webp',
                'video/mp4',
            ]),
            'size_bytes' => fake()->numberBetween(1_000, 10_000_000),
            'checksum_sha256' => hash('sha256', $filename),
            'original_filename' => $filename,
        ];
    }

    public function withPublicUrl(string $publicUrl): static
    {
        return $this->afterMaking(function (MediaAsset $mediaAsset) use ($publicUrl): void {
            $mediaAsset->public_url = $publicUrl;
        });
    }
}
