<?php

namespace App\Domain\Content\Models;

use App\Domain\Admin\Models\AdminUserProjection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function userProjection(): BelongsTo
    {
        return $this->belongsTo(
            AdminUserProjection::class,
            'convolab_user_id',
            'convolab_id',
        );
    }
}
