<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSentence extends Model
{
    protected $table = 'content_sentences';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'variations' => 'array',
            'selected' => 'boolean',
        ];
    }

    public function dialogue(): BelongsTo
    {
        return $this->belongsTo(ContentDialogue::class, 'dialogue_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(ContentSpeaker::class, 'speaker_id');
    }
}
