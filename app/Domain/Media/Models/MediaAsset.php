<?php

namespace App\Domain\Media\Models;

use App\Domain\Flashcards\Models\Card;
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
    use HasFactory, HasUlids;

    public const MAX_PUBLIC_URL_LENGTH = 2048;

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
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    throw new InvalidArgumentException('public_url must be a valid URL.');
                }

                if (mb_strlen($value) > self::MAX_PUBLIC_URL_LENGTH) {
                    throw new InvalidArgumentException('public_url must not exceed '.self::MAX_PUBLIC_URL_LENGTH.' characters.');
                }

                $scheme = parse_url($value, PHP_URL_SCHEME);

                if (! in_array($scheme, ['http', 'https'], true)) {
                    throw new InvalidArgumentException('public_url must use the http or https scheme.');
                }

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
                return self::normalizeOriginalFilename($value);
            },
            set: fn (?string $value): ?string => self::normalizeOriginalFilename($value),
        );
    }

    private static function normalizeOriginalFilename(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $filename = basename(str_replace('\\', '/', trim($value)));

        return in_array($filename, ['', '.', '..'], true) ? null : $filename;
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
