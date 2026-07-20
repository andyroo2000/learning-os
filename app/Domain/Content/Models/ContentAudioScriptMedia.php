<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

class ContentAudioScriptMedia extends Model
{
    protected $table = 'content_audio_script_media';

    public $incrementing = false;

    protected $keyType = 'string';
}
