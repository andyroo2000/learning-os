<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('convolab_beta_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 254)->unique();
            $table->json('devices');
            $table->text('message');
            // Keep the approval record even when an invite is revoked in Invite Codes.
            $table->uuid('invite_code_id')->nullable()->index();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamps();
            $table->index(['invitation_sent_at', 'created_at', 'id'], 'beta_requests_inbox_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('convolab_beta_requests');
    }
};
