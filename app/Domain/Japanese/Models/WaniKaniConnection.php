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
            'vocabulary_assignments_synced_through_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'review_count' => 'integer',
            'review_count_updated_at' => 'immutable_datetime',
            'transfer_bridge_enabled' => 'boolean',
            'transfer_bridge_enabled_at' => 'immutable_datetime',
            'transfer_bridge_seeded_at' => 'immutable_datetime',
            'transfer_bridge_last_imported_at' => 'immutable_datetime',
        ];
    }
}
