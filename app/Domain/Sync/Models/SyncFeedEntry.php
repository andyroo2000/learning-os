<?php

namespace App\Domain\Sync\Models;

use App\Domain\Sync\Enums\SyncFeedOperation;
use App\Models\User;
use Database\Factories\SyncFeedEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'domain', 'resource_type', 'resource_id', 'operation', 'server_recorded_at', 'payload'])]
class SyncFeedEntry extends Model
{
    /** @use HasFactory<SyncFeedEntryFactory> */
    use HasFactory;

    protected $primaryKey = 'checkpoint';

    public $incrementing = true;

    public $timestamps = false;

    protected static function newFactory(): SyncFeedEntryFactory
    {
        return SyncFeedEntryFactory::new();
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
            'operation' => SyncFeedOperation::class,
            'server_recorded_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
