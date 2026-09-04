<?php

namespace App\Console\Support;

final class ConvoLabMediaImportState
{
    public array $mediaByPath = [];

    public array $pathBySourceMediaId = [];

    public array $userIdBySourceMediaId = [];

    public array $unavailableSourceMediaIds = [];

    public array $skippedUnavailableCardMediaPairs = [];

    public array $cardsBySourceId = [];
}
