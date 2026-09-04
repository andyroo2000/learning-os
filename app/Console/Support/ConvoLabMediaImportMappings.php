<?php

namespace App\Console\Support;

final readonly class ConvoLabMediaImportMappings
{
    /**
     * @param  array<string, int>  $userIds
     * @param  array<string, string>  $importJobIds
     * @param  array<string, array{card_id: string, user_id: int, deck_id: string, course_id: string|null}>  $cardsBySourceId
     */
    public function __construct(
        public array $userIds,
        public array $importJobIds,
        public array $cardsBySourceId,
    ) {}
}
