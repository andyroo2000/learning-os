<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentCourseCoreItem extends Model
{
    protected $table = 'content_course_core_items';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['components' => 'array'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(ContentCourse::class, 'course_id');
    }
}
