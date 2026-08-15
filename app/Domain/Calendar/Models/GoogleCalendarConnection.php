<?php

namespace App\Domain\Calendar\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }
}
