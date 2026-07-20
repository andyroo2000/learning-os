<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSpeaker extends Model
{
    protected $table = 'content_speakers';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public function dialogue(): BelongsTo
    {
        return $this->belongsTo(ContentDialogue::class, 'dialogue_id');
    }
}
