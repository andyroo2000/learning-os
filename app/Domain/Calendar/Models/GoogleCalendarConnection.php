<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\GoogleCalendarSyncErrorCode;
use App\Domain\Calendar\Enums\GoogleCalendarSyncStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class GoogleCalendarConnection extends Model
{
    protected $guarded = ['*'];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'sync_cursors',
        'token_expires_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<GoogleCalendarEventMirror, $this> */
    public function eventMirrors(): HasMany
    {
        return $this->hasMany(GoogleCalendarEventMirror::class);
    }

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'sync_cursors' => 'encrypted:array',
            'scopes' => 'array',
            'settings' => 'array',
            'token_expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'sync_status' => GoogleCalendarSyncStatus::class,
            'sync_error_code' => GoogleCalendarSyncErrorCode::class,
            'sync_status_at' => 'immutable_datetime',
        ];
    }
}
