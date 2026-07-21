<?php

namespace App\Domain\Admin\Models;

use App\Domain\Content\Models\ContentCourse;
use App\Domain\Content\Models\ContentEpisode;
use App\Domain\Content\Support\ContentSourceSystem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminUserProjection extends Model
{
    protected $table = 'admin_user_projections';

    protected $primaryKey = 'convolab_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'onboarding_completed' => 'boolean',
            'seen_sample_content_guide' => 'boolean',
            'seen_custom_content_guide' => 'boolean',
            'email_verified' => 'boolean',
            'email_verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contentEpisodes(): HasMany
    {
        return $this->hasMany(ContentEpisode::class, 'convolab_user_id', 'convolab_id')
            ->where('source_system', ContentSourceSystem::CONVOLAB);
    }

    public function contentCourses(): HasMany
    {
        return $this->hasMany(ContentCourse::class, 'convolab_user_id', 'convolab_id')
            ->where('source_system', ContentSourceSystem::CONVOLAB);
    }
}
