<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONVOLAB_SOURCE = 'convolab';

    public function up(): void
    {
        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            $table->index('source_system', 'admin_users_source_system_idx');
        });

        Schema::table('admin_invite_codes', function (Blueprint $table): void {
            $table->string('source_system', 32)->default(self::CONVOLAB_SOURCE);
            $table->index('source_system', 'admin_invites_source_system_idx');
        });

        Schema::create('convolab_email_verification_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->index('user_id', 'convolab_verification_tokens_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convolab_email_verification_tokens');

        Schema::table('admin_invite_codes', function (Blueprint $table): void {
            $table->dropIndex('admin_invites_source_system_idx');
            $table->dropColumn('source_system');
        });

        Schema::table('admin_user_projections', function (Blueprint $table): void {
            $table->dropIndex('admin_users_source_system_idx');
            $table->dropColumn('source_system');
        });
    }
};
