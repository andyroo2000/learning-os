<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'admin_sentence_script_tests_order_idx';

    private const ACTOR_ORDER_INDEX = 'admin_sentence_script_tests_actor_order_idx';

    public function up(): void
    {
        Schema::table('admin_sentence_script_tests', function (Blueprint $table): void {
            $table->dropIndex(self::OLD_INDEX);
            $table->index(
                ['actor_convolab_user_id', 'created_at', 'id'],
                self::ACTOR_ORDER_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('admin_sentence_script_tests', function (Blueprint $table): void {
            $table->dropIndex(self::ACTOR_ORDER_INDEX);
            $table->index(['created_at', 'id'], self::OLD_INDEX);
        });
    }
};
