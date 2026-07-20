<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAudioScriptRender extends Model
{
    protected $table = 'content_audio_script_renders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'numeric_speed' => 'float',
            'timing_data' => 'array',
            'approx_duration_seconds' => 'float',
        ];
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(ContentAudioScript::class, 'script_id');
    }
}
