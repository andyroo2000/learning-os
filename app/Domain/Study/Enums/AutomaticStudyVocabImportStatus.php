<?php

namespace App\Domain\Study\Enums;

enum AutomaticStudyVocabImportStatus: string
{
    case Generating = 'generating';
    case Imported = 'imported';
    case Error = 'error';
}
