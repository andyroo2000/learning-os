<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentCourse extends Model
{
    protected $table = 'content_courses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'is_sample_content' => 'boolean',
            'is_test_course' => 'boolean',
            'generation_revision' => 'integer',
            'script_json' => 'array',
            'script_units_json' => 'array',
            'timing_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coreItems(): HasMany
    {
        return $this->hasMany(ContentCourseCoreItem::class, 'course_id');
    }

    public function courseEpisodes(): HasMany
    {
        return $this->hasMany(ContentEpisodeCourse::class, 'convolab_course_id');
    }
}
