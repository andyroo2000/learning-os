<?php

namespace App\Domain\Media\Contracts;

interface StaticMediaObjectWriter
{
    public function putPublic(string $objectPath, string $contents, string $contentType): void;

    public function read(string $objectPath): string;

    public function delete(string $objectPath): void;
}
