<?php

namespace App\Domain\Media\Models;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Values\OriginalFilename;
use App\Domain\Media\Values\PublicUrl;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use InvalidArgumentException;

/**
 * public_url is intentionally excluded from fillable; assign it explicitly after validation.
 *
 * @throws InvalidArgumentException when public_url violates model invariants.
 */
#[Fillable(['user_id', 'disk', 'path', 'mime_type', 'size_bytes', 'checksum_sha256', 'original_filename'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings;

    public const MAX_DISK_LENGTH = 255;

    public const MAX_PATH_LENGTH = 255;

    public const MAX_MIME_TYPE_LENGTH = 255;

    public const MAX_ORIGINAL_FILENAME_LENGTH = 255;

    public const MAX_PUBLIC_URL_LENGTH = 2048;

    /**
     * Largest integer JavaScript clients can parse from JSON without precision loss.
     */
    public const MAX_JSON_SAFE_SIZE_BYTES = 9_007_199_254_740_991;

    public const DISK_MEDIA = 'media';

    public const ALLOWED_DISKS = [
        self::DISK_MEDIA,
    ];

    public const PATH_ABSOLUTE_PATTERN = '~^(?:[\\\\/]|[a-zA-Z]:[\\\\/])~';

    public const PATH_TRAVERSAL_PATTERN = '~(^|[\\\\/])\\.\\.([\\\\/]|$)~';

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            // App-level inputs are PHP ints; product upload caps belong at the upload boundary.
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function publicUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === '' ? null : $value,
            set: function (?string $value): ?string {
                if ($value === null) {
                    return null;
                }

                $value = trim($value);

                if ($value === '') {
                    return null;
                }

                // This is a model invariant guard; public write paths should still validate first.
                PublicUrl::assertValid($value, self::MAX_PUBLIC_URL_LENGTH);

                return $value;
            },
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function originalFilename(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                // Keep raw imported rows safe to serialize until a backfill exists.
                return OriginalFilename::normalize($value);
            },
            set: fn (?string $value): ?string => OriginalFilename::normalize($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function checksumSha256(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                $value = $value === null ? null : trim($value);

                return $value === null || $value === '' ? null : strtolower($value);
            },
            set: function (?string $value): ?string {
                $value = $value === null ? null : trim($value);

                return $value === null || $value === '' ? null : strtolower($value);
            },
        );
    }

    /**
     * @return BelongsToMany<Card, $this>
     */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_media')
            ->withTimestamps();
    }
}
