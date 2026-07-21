<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONVOLAB_TIMESTAMP_PRECISION = 3;

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('convolab_password_hash')->nullable();
        });

        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->string('proficiency_level', 32)->default('beginner');
            $table->boolean('seen_sample_content_guide')->default(false);
            $table->boolean('seen_custom_content_guide')->default(false);
            $table->boolean('email_verified')->default(false);
            $table->timestampTz('email_verified_at', self::CONVOLAB_TIMESTAMP_PRECISION)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->dropColumn([
                'proficiency_level',
                'seen_sample_content_guide',
                'seen_custom_content_guide',
                'email_verified',
                'email_verified_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('convolab_password_hash');
        });
    }
};
