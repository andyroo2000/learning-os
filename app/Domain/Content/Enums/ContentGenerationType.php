<?php

namespace App\Domain\Content\Enums;

enum ContentGenerationType: string
{
    case Dialogue = 'dialogue';
    case Script = 'script';
    case Course = 'course';
}
