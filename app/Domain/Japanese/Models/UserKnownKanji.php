<?php

namespace App\Domain\Japanese\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserKnownKanji extends Model
{
    protected $table = 'user_known_kanji';

    protected $guarded = ['*'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEffectivelyKnown(): bool
    {
        return $this->wanikani_passed_at !== null || $this->manually_added_at !== null;
    }

    protected function casts(): array
    {
        return [
            'wanikani_subject_id' => 'integer',
            'wanikani_passed_at' => 'immutable_datetime',
            'manually_added_at' => 'immutable_datetime',
        ];
    }
}
