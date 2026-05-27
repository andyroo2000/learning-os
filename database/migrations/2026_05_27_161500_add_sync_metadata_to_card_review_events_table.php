<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->string('client_event_id')->nullable()->after('reviewed_at');
            $table->string('device_id')->nullable()->after('client_event_id');
            $table->timestamp('client_created_at')->nullable()->after('device_id');
            $table->unique(['device_id', 'client_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_review_events', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'client_event_id']);
            $table->dropColumn([
                'client_event_id',
                'device_id',
                'client_created_at',
            ]);
        });
    }
};
