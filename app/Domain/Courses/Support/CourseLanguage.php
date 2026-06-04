<?php

namespace App\Domain\Courses\Support;

final class CourseLanguage
{
    private function __construct() {}

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
