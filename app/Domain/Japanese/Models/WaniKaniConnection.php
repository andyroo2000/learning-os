<?php

namespace App\Domain\Japanese\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaniKaniConnection extends Model
{
    protected $table = 'wanikani_connections';

    protected $guarded = ['*'];

    protected $hidden = ['api_token'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'assignments_synced_through_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
