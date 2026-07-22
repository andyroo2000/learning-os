<?php

namespace App\Domain\Auth\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConvoLabOAuthIdentity extends Model
{
    public const GOOGLE_PROVIDER = 'google';

    protected $table = 'convolab_oauth_identities';

    protected function casts(): array
    {
        return [
            'access_granted_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
