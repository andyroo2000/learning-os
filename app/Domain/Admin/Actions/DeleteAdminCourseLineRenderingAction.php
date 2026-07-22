<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminCourseLineRendering;
use App\Domain\Admin\Support\AdminCourseLineRenderingStorage;
use App\Domain\Content\Support\ContentCourseId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DeleteAdminCourseLineRenderingAction
{
    public function __construct(private AdminCourseLineRenderingStorage $storage) {}

    public function handle(string $courseId, string $renderingId): void
    {
        $courseId = ContentCourseId::normalize($courseId);
        $renderingId = strtolower(trim($renderingId));
        if (! Str::isUuid($renderingId)) {
            throw AdminMutationException::courseLineRenderingNotFound();
        }
        $rendering = DB::transaction(function () use ($courseId, $renderingId): AdminCourseLineRendering {
            $rendering = AdminCourseLineRendering::query()
                ->whereKey($renderingId)
                ->where('course_id', $courseId)
                ->lockForUpdate()
                ->first();
            if (! $rendering instanceof AdminCourseLineRendering) {
                throw AdminMutationException::courseLineRenderingNotFound();
            }

            $rendering->delete();

            return $rendering;
        });

        $path = $this->storage->ownedPath($rendering);
        if ($path !== null) {
            $this->storage->deletePaths([$path]);
        }
    }
}
