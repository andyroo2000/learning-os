<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_flags', function (Blueprint $table): void {
            $table->boolean('readingsEnabled')->default(false);
            $table->boolean('studyTimeEnabled')->default(false);
        });
        // Start the private beta with Journal only. Admins can enable sections afterward.
        DB::table('feature_flags')->update(['flashcardsEnabled' => false]);
    }

    public function down(): void
    {
        Schema::table('feature_flags', fn (Blueprint $table) => $table->dropColumn(['readingsEnabled', 'studyTimeEnabled']));
    }
};
