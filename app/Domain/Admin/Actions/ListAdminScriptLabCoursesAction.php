<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Content\Models\ContentCourse;
use Illuminate\Database\Eloquent\Collection;

final class ListAdminScriptLabCoursesAction
{
    /** @return Collection<int, ContentCourse> */
    public function handle(): Collection
    {
        return ContentCourse::query()
            ->where('is_test_course', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'title',
                'status',
                'created_at',
                'script_json',
                'script_units_json',
                'audio_url',
            ]);
    }
}
