<?php

namespace Tests\Feature\Rehearsal;

use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportIdentifierTest extends ConvoLabRehearsalImportTestCase
{
    public function test_rejects_a_non_uuid_convolab_import_job_identifier(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_import_jobs')
            ->update(['id' => 'not-a-uuid']);

        $this->assertImportFailsWith('Convo Lab import job [not-a-uuid] does not have a valid UUID.');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('study_import_jobs', 0);
    }

    public function test_rejects_a_non_uuid_convolab_card_identifier(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('study_cards')->update(['id' => 'not-a-uuid']);

        $this->assertImportFailsWith('Convo Lab card [not-a-uuid] does not have a valid UUID.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_a_non_uuid_convolab_note_identifier(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $source->table('study_notes')->update(['id' => 'not-a-uuid']);
        $source->table('study_cards')->update(['noteId' => 'not-a-uuid']);

        $this->assertImportFailsWith('Convo Lab note [not-a-uuid] does not have a valid UUID.');

        $this->assertDatabaseCount('users', 0);
    }
}
