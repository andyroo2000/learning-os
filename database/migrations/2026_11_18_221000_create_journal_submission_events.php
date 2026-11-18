<?php

use App\Domain\Journal\Support\BackfillJournalSubmissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_submission_events', function (Blueprint $table): void {
            $table->string('source_key', 40)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Keep aggregate history after deleting an entry, but not after deleting its owner.
            $table->ulid('root_entry_id');
            $table->string('kind', 12);
            $table->unsignedInteger('characters');
            $table->timestamp('submitted_at', 6);
            $table->index(['submitted_at', 'user_id']);
            $table->index(['root_entry_id', 'kind']);
        });
        app(BackfillJournalSubmissions::class)->handle();
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_submission_events');
    }
};
