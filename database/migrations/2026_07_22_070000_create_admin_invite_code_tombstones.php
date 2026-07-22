<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TIMESTAMP_PRECISION = 3;

    public function up(): void
    {
        Schema::create('admin_invite_code_tombstones', function (Blueprint $table): void {
            $table->uuid('invite_code_id')->primary();
            $table->timestampTz('deleted_at', self::TIMESTAMP_PRECISION);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invite_code_tombstones');
    }
};
