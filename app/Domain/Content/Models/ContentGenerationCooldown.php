<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

final class ContentGenerationCooldown extends Model
{
    protected $table = 'content_generation_cooldowns';

    protected $primaryKey = 'convolab_user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'available_at' => 'immutable_datetime',
        ];
    }
}
