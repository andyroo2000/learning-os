<?php

namespace App\Domain\Calendar\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GoogleCalendarEventMirror extends Model
{
    protected $guarded = ['*'];

    /** @return BelongsTo<GoogleCalendarConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleCalendarConnection::class, 'google_calendar_connection_id');
    }

    protected function casts(): array
    {
        return [
            'original_start_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'all_day' => 'boolean',
            'provider_updated_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
