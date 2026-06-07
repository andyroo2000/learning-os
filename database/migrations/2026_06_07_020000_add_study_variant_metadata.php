<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_card_drafts', function (Blueprint $table): void {
            $table->string('variant_group_id', 64)->nullable()->after('preview_image_json');
            $table->string('variant_sentence_id', 64)->nullable()->after('variant_group_id');
            $table->string('variant_kind', 64)->nullable()->after('variant_sentence_id');
            $table->unsignedSmallInteger('variant_stage')->nullable()->after('variant_kind');
            $table->string('variant_status', 16)->nullable()->after('variant_stage');
            $table->timestamp('variant_unlocked_at')->nullable()->after('variant_status');
        });

        Schema::table('cards', function (Blueprint $table): void {
            $table->string('variant_group_id', 64)->nullable()->after('scheduler_state');
            $table->string('variant_sentence_id', 64)->nullable()->after('variant_group_id');
            $table->string('variant_kind', 64)->nullable()->after('variant_sentence_id');
            $table->unsignedSmallInteger('variant_stage')->nullable()->after('variant_kind');
            $table->string('variant_status', 16)->nullable()->after('variant_stage');
            $table->timestamp('variant_unlocked_at')->nullable()->after('variant_status');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);
        });

        Schema::table('study_card_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'variant_group_id',
                'variant_sentence_id',
                'variant_kind',
                'variant_stage',
                'variant_status',
                'variant_unlocked_at',
            ]);
        });
    }
};
