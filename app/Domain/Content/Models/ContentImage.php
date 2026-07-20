<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentImage extends Model
{
    protected $table = 'content_images';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContentEpisode::class, 'episode_id');
    }
}
