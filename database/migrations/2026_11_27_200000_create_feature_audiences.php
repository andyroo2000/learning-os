<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_audiences', function (Blueprint $table): void {
            $table->string('feature', 40)->primary();
            $table->unsignedInteger('version')->default(0);
        });
        foreach (['flashcardsEnabled', 'readingsEnabled', 'studyTimeEnabled', 'learnEnabled'] as $feature) {
            DB::table('feature_audiences')->insert(['feature' => $feature, 'version' => 0]);
        }
        Schema::create('feature_access_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('name_key', 80)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('feature_group_members', function (Blueprint $table): void {
            $table->foreignUuid('group_id')->constrained('feature_access_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['group_id', 'user_id']);
            $table->index(['user_id', 'group_id']);
        });
        Schema::create('feature_audience_users', function (Blueprint $table): void {
            $table->string('feature', 40);
            $table->foreign('feature')->references('feature')->on('feature_audiences')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['feature', 'user_id']);
            $table->index(['user_id', 'feature']);
        });
        Schema::create('feature_audience_groups', function (Blueprint $table): void {
            $table->string('feature', 40);
            $table->foreign('feature')->references('feature')->on('feature_audiences')->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained('feature_access_groups')->cascadeOnDelete();
            $table->primary(['feature', 'group_id']);
            $table->index(['group_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_audience_groups');
        Schema::dropIfExists('feature_audience_users');
        Schema::dropIfExists('feature_group_members');
        Schema::dropIfExists('feature_access_groups');
        Schema::dropIfExists('feature_audiences');
    }
};
