<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_analyses', function (Blueprint $table): void {
            $table->foreignUlid('clarification_id')->nullable()->constrained('journal_clarifications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_analyses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('clarification_id');
        });
    }
};
