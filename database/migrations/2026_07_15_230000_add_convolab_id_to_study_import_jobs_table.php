<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const USER_CONVOLAB_ID_UNIQUE = 'study_import_jobs_user_convolab_id_unique';

    public function up(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->after('user_id');
            $table->unique(['user_id', 'convolab_id'], self::USER_CONVOLAB_ID_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::table('study_import_jobs', function (Blueprint $table): void {
            $table->dropUnique(self::USER_CONVOLAB_ID_UNIQUE);
            $table->dropColumn('convolab_id');
        });
    }
};
