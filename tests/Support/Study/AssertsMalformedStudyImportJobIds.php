<?php

namespace Tests\Support\Study;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait AssertsMalformedStudyImportJobIds
{
    /**
     * @param  callable(): void  $callback
     */
    protected function captureQueriesForExpectedMalformedImportJobNotFound(callable $callback): Collection
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $callback();
            $this->fail('Expected malformed import job IDs to be hidden as not found.');
        } catch (ModelNotFoundException) {
            return collect(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    protected function assertNoStudyImportJobsQueried(Collection $queries): void
    {
        $this->assertCount(
            0,
            $queries->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'study_import_jobs')),
            'Malformed import job IDs should return not-found before querying study_import_jobs.',
        );
    }
}
