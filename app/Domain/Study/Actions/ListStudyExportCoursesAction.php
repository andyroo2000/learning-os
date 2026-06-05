<?php

namespace App\Domain\Study\Actions;

use App\Domain\Courses\Models\Course;
use Illuminate\Database\Eloquent\Collection;

class ListStudyExportCoursesAction
{
    /**
     * @return Collection<int, Course>
     */
    public function handle(int $userId): Collection
    {
        return Course::query()
            ->where('user_id', $userId)
            // The SoftDeletes global scope keeps deleted rows out of this current-state export.
            ->orderBy('id')
            // Unbounded by design: clients use this complete section during full export/resync.
            ->get();
    }
}
