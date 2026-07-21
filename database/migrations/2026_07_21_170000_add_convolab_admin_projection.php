<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->unique('users_convolab_id_unique');
            $table->boolean('convolab_admin_visible')->default(false);
            $table->string('display_name')->nullable();
            $table->string('avatar_color', 32)->nullable();
            $table->text('avatar_url')->nullable();
            $table->string('role', 32)->default('user');
            $table->string('preferred_study_language', 16)->default('ja');
            $table->string('preferred_native_language', 16)->default('en');
            $table->boolean('onboarding_completed')->default(false);
            $table->index(
                ['convolab_admin_visible', 'created_at', 'id'],
                'users_convolab_visible_created_id_idx',
            );
        });

        Schema::create('admin_invite_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('convolab_used_by')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['created_at', 'id'], 'admin_invites_created_id_idx');
            $table->index('convolab_used_by', 'admin_invites_convolab_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invite_codes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_convolab_visible_created_id_idx');
            $table->dropUnique('users_convolab_id_unique');
            $table->dropColumn([
                'convolab_id',
                'convolab_admin_visible',
                'display_name',
                'avatar_color',
                'avatar_url',
                'role',
                'preferred_study_language',
                'preferred_native_language',
                'onboarding_completed',
            ]);
        });
    }
};
