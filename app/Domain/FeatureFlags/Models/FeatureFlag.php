<?php

namespace App\Domain\FeatureFlags\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

// This adopted table keeps Convo Lab's physical camelCase columns. New Learning OS domains use snake_case.
#[Fillable([
    'dialoguesEnabled',
    'scriptsEnabled',
    'audioCourseEnabled',
    'flashcardsEnabled',
])]
class FeatureFlag extends Model
{
    public const DEFAULT_ID = 'default';

    public const CREATED_AT = null;

    public const UPDATED_AT = 'updatedAt';

    public $incrementing = false;

    protected $table = 'feature_flags';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dialoguesEnabled' => 'boolean',
            'scriptsEnabled' => 'boolean',
            'audioCourseEnabled' => 'boolean',
            'flashcardsEnabled' => 'boolean',
            'updatedAt' => 'datetime',
        ];
    }
}
