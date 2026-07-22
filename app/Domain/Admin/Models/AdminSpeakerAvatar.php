<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSpeakerAvatar extends Model
{
    protected $table = 'admin_speaker_avatars';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
