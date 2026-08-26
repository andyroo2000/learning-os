<?php

namespace App\Domain\Flashcards\Enums;

enum CardSourceKind: string
{
    case Manual = 'manual';
    case BulkImport = 'bulk_import';
    case WaniKani = 'wanikani';
    case LessonFollowup = 'lesson_followup';
}
