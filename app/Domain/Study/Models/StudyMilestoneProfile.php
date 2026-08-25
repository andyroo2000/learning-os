<?php

namespace App\Domain\Study\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyMilestoneProfile extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'initialized_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
