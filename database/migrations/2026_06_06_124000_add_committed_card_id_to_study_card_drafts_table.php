<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_card_drafts', function (Blueprint $table): void {
            // Records the draft commit idempotency key. Card conflict/deletion semantics stay in CreateCardAction.
            // No index: commit retries always load the draft by primary key before checking this value.
            $table->ulid('committed_card_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('study_card_drafts', function (Blueprint $table): void {
            $table->dropColumn('committed_card_id');
        });
    }
};
