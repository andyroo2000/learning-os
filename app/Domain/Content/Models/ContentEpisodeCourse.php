<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentEpisodeCourse extends Model
{
    protected $table = 'content_episode_courses';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContentEpisode::class, 'episode_id');
    }
}
