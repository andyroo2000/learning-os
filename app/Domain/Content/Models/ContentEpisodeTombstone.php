<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentEpisodeTombstone extends Model
{
    protected $table = 'content_episode_tombstones';

    protected $primaryKey = 'episode_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
