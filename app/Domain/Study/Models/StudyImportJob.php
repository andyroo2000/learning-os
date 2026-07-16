<?php

namespace App\Domain\Study\Models;

use App\Domain\Study\Enums\StudyImportStatus;
use App\Models\Concerns\ResolvesCanonicalUlidRouteBindings;
use App\Models\User;
use Database\Factories\StudyImportJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Import ownership is server-assigned so future upload/session flows cannot claim another user.
class StudyImportJob extends Model
{
    /** @use HasFactory<StudyImportJobFactory> */
    use HasFactory, HasUlids, ResolvesCanonicalUlidRouteBindings;

    public const SOURCE_TYPE_ANKI_COLPKG = 'anki_colpkg';

    public const DEFAULT_DECK_NAME = 'Japanese';

    public const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    public const SOURCE_UPLOAD_FOLDER = 'study/imports';

    public const UPLOAD_SESSION_TTL_MINUTES = 60;

    public const PROCESSING_TIMEOUT_MINUTES = 60;

    public const MAX_ASYNC_IMPORT_BYTES = 2_147_483_648;

    public const MAX_SOURCE_FILENAME_LENGTH = 255;

    public const MAX_SOURCE_CONTENT_TYPE_LENGTH = 255;

    public const MAX_STATUS_LENGTH = 32;

    public const MAX_SOURCE_TYPE_LENGTH = 64;

    public const ALLOWED_CONTENT_TYPES = [
        self::DEFAULT_CONTENT_TYPE,
        'application/zip',
        'application/x-zip-compressed',
        'multipart/x-zip',
    ];

    protected static function booted(): void
    {
        static::updating(function (StudyImportJob $importJob): void {
            if ($importJob->isDirty('user_id')) {
                throw new LogicException('Study import job owner cannot be changed.');
            }

            if ($importJob->isDirty('convolab_id')) {
                throw new LogicException('Study import job ConvoLab identifier cannot be changed.');
            }
        });
    }

    protected static function newFactory(): StudyImportJobFactory
    {
        return StudyImportJobFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StudyImportStatus::class,
            'source_size_bytes' => 'integer',
            'preview_json' => 'array',
            'summary_json' => 'array',
            'started_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'upload_completed_at' => 'datetime',
            'upload_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientId(): string
    {
        $convoLabId = $this->getAttribute('convolab_id');

        return is_string($convoLabId) && $convoLabId !== ''
            ? $convoLabId
            : (string) $this->getKey();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StudyImportStatus::Pending->value,
            StudyImportStatus::Processing->value,
        ]);
    }
}
