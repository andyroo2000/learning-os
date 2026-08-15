<?php

namespace App\Domain\Calendar\Models;

use Illuminate\Database\Eloquent\Model;

final class GoogleCalendarConnectIntent extends Model
{
    protected $primaryKey = 'state_hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['state_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime'];
    }
}
