<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Support\ContentAudioScriptJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentAudioScript extends Model
{
    protected $table = 'content_audio_scripts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'generation_metadata' => 'array',
            'render_generation_attempt' => 'integer',
            'image_generation_attempt' => 'integer',
        ];
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(ContentEpisode::class, 'episode_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ContentAudioScriptSegment::class, 'script_id');
    }

    public function renders(): HasMany
    {
        return $this->hasMany(ContentAudioScriptRender::class, 'script_id');
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(ContentAudioScriptGenerationJob::class, 'script_id');
    }

    public function hasGenerationInProgress(): bool
    {
        return $this->generationJobs()
            ->whereIn('state', [ContentAudioScriptJob::STATE_WAITING, ContentAudioScriptJob::STATE_ACTIVE])
            ->exists();
    }
}
