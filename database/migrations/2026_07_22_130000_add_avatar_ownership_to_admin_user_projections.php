<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->string('avatar_source_system', 32)
                ->default('convolab');
        });
    }

    public function down(): void
    {
        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->dropColumn('avatar_source_system');
        });
    }
};
