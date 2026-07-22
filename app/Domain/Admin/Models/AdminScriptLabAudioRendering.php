<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminScriptLabAudioRendering extends Model
{
    protected $table = 'admin_script_lab_audio_renderings';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'speed' => 'float',
            'duration_seconds' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }
}
