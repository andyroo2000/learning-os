<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminInviteCode extends Model
{
    protected $table = 'admin_invite_codes';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function adminUserProjection(): BelongsTo
    {
        return $this->belongsTo(AdminUserProjection::class, 'convolab_used_by', 'convolab_id');
    }
}
