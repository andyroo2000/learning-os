<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentDialogue extends Model
{
    protected $table = 'content_dialogues';

    public $incrementing = false;

    protected $keyType = 'string';

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContentEpisode::class, 'episode_id');
    }

    public function speakers(): HasMany
    {
        return $this->hasMany(ContentSpeaker::class, 'dialogue_id');
    }

    public function sentences(): HasMany
    {
        return $this->hasMany(ContentSentence::class, 'dialogue_id');
    }

    public function imageGenerationJobs(): HasMany
    {
        return $this->hasMany(ContentImageGenerationJob::class, 'dialogue_id');
    }
}
