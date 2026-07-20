<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentAudioScriptSegment extends Model
{
    protected $table = 'content_audio_script_segments';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'image_generated_at' => 'datetime',
        ];
    }

    public function script(): BelongsTo
    {
        return $this->belongsTo(ContentAudioScript::class, 'script_id');
    }

    public function imageMedia(): BelongsTo
    {
        return $this->belongsTo(ContentAudioScriptMedia::class, 'image_media_id');
    }
}
