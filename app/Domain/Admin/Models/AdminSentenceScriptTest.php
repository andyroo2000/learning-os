<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminSentenceScriptTest extends Model
{
    protected $table = 'admin_sentence_script_tests';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return [
            'units_json' => 'array',
            'estimated_duration_secs' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }
}
