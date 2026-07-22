<?php

namespace App\Domain\Admin\Models;

use App\Domain\Content\Models\ContentCourse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdminCourseLineRendering extends Model
{
    protected $table = 'admin_course_line_renderings';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'unit_index' => 'integer',
            'speed' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(ContentCourse::class, 'course_id');
    }
}
