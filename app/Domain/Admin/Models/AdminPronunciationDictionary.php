<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPronunciationDictionary extends Model
{
    protected $table = 'admin_pronunciation_dictionaries';

    protected $primaryKey = 'locale';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'keep_kanji' => 'array',
            'force_kana' => 'array',
            'verb_kana' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
