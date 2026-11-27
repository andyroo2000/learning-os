<?php

namespace App\Domain\FeatureFlags\Contracts;

interface AudienceUserSearch
{
    /** Return canonical user IDs and a paginated users/total/page response, regardless of search provider. */
    public function search(array $filters): array;
}
