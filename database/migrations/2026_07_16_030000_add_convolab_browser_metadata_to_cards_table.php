<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const CONVOLAB_ID_UNIQUE = 'cards_convolab_id_unique';

    public const CONVOLAB_NOTE_ID_INDEX = 'cards_convolab_note_id_idx';

    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->uuid('convolab_id')->nullable()->after('id');
            $table->uuid('convolab_note_id')->nullable()->after('convolab_id');
            $table->timestamp('convolab_note_created_at', 3)->nullable()->after('convolab_note_id');
            $table->timestamp('convolab_note_updated_at', 3)->nullable()->after('convolab_note_created_at');

            $table->unique('convolab_id', self::CONVOLAB_ID_UNIQUE);
            $table->index('convolab_note_id', self::CONVOLAB_NOTE_ID_INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropUnique(self::CONVOLAB_ID_UNIQUE);
            $table->dropIndex(self::CONVOLAB_NOTE_ID_INDEX);
            $table->dropColumn([
                'convolab_id',
                'convolab_note_id',
                'convolab_note_created_at',
                'convolab_note_updated_at',
            ]);
        });
    }
};
