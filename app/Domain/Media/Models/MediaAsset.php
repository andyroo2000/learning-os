<?php

namespace App\Domain\Media\Models;

use App\Domain\Flashcards\Models\Card;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['disk', 'path', 'public_url', 'mime_type', 'size_bytes', 'checksum_sha256', 'original_filename'])]
class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory, HasUlids;

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
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

                // Internal writers should validate public URLs before assignment.
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    return null;
                }

                $scheme = parse_url($value, PHP_URL_SCHEME);

                if (! in_array($scheme, ['http', 'https'], true)) {
                    return null;
                }

                return $value;
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
