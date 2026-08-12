<?php

namespace Tests\Unit\Study;

use App\Domain\Study\Support\StudyImportUploadPath;
use PHPUnit\Framework\TestCase;

class StudyImportUploadPathTest extends TestCase
{
    public function test_archive_path_and_safety_prefix_share_the_canonical_format(): void
    {
        $prefix = StudyImportUploadPath::prefixForImportJob(42, '01kzsz0vrhx6gaak37ptj029t2');

        $this->assertSame('study/imports/42/01kzsz0vrhx6gaak37ptj029t2/', $prefix);
        $this->assertSame(
            $prefix.'Core.COLPKG',
            StudyImportUploadPath::forImportJob(
                42,
                '01kzsz0vrhx6gaak37ptj029t2',
                'client\\folder/Core.COLPKG',
            ),
        );
    }
}
