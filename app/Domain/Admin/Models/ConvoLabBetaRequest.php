<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConvoLabBetaRequest extends Model
{
    use HasUuids;

    protected $table = 'convolab_beta_requests';

    protected $fillable = ['email', 'devices', 'message'];

    protected function casts(): array
    {
        return [
            'devices' => 'array',
            'approved_at' => 'immutable_datetime',
            'invitation_sent_at' => 'immutable_datetime',
            'sending_started_at' => 'immutable_datetime',
        ];
    }

    public function inviteCode(): BelongsTo
    {
        return $this->belongsTo(AdminInviteCode::class, 'invite_code_id');
    }
}
