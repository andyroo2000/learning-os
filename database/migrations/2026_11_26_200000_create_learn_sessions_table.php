<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learn_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('lesson_id', 80);
            $table->json('state');
            $table->unsignedInteger('version')->default(1);
            $table->uuid('pending_request_id')->nullable();
            $table->timestamp('pending_at')->nullable();
            $table->uuid('last_request_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learn_sessions');
    }
};
