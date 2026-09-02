<?php

namespace Tests\Support\Study;

trait TracksStudyImportArchiveSnapshots
{
    /**
     * @return list<string>
     */
    protected function studyImportSnapshotPaths(): array
    {
        $paths = glob(sys_get_temp_dir().'/study-import-archive-*');

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return $paths;
    }
}
