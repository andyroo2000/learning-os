<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentAudioScriptMedia extends Model
{
    protected $table = 'content_audio_script_media';

    public $incrementing = false;

    protected $keyType = 'string';

    public function segments(): HasMany
    {
        return $this->hasMany(ContentAudioScriptSegment::class, 'image_media_id');
    }
}
