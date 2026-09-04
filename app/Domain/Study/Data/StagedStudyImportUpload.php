<?php

namespace App\Domain\Study\Data;

final class StagedStudyImportUpload
{
    /** @param resource $contents */
    public function __construct(
        private $contents,
        public readonly int $sizeBytes,
    ) {}

    /** @return resource */
    public function contents()
    {
        return $this->contents;
    }

    public function close(): void
    {
        fclose($this->contents);
    }
}
