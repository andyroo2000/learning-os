<?php

namespace App\Domain\Study\Support;

use LogicException;

final class StudyImportArchiveSnapshot
{
    private bool $closed = false;

    public function __construct(
        private readonly string $path,
    ) {}

    public function path(): string
    {
        if ($this->closed) {
            throw new LogicException('Study import archive snapshot has already been closed.');
        }

        return $this->path;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        @unlink($this->path);
        $this->closed = true;
    }

    public function __destruct()
    {
        $this->close();
    }
}
