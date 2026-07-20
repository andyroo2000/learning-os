<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('feature_flags')) {
            return;
        }

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('dialoguesEnabled')->default(true);
            $table->boolean('scriptsEnabled')->default(true);
            $table->boolean('audioCourseEnabled')->default(true);
            $table->boolean('flashcardsEnabled')->default(true);
            $table->timestamp('updatedAt', 3);
        });
    }

    public function down(): void
    {
        // This table may predate Learning OS in a restored Convo Lab database.
        // Retaining it is safer than deleting source-owned data during rollback.
    }
};
