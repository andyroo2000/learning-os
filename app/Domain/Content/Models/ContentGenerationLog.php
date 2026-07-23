<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

final class ContentGenerationLog extends Model
{
    protected $table = 'generation_logs';

    protected $primaryKey = 'id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'createdAt' => 'immutable_datetime',
        ];
    }
}
