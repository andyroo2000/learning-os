<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabAudioScriptRendersSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('audio_script_renders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('scriptId');
            $table->string('speed');
            $table->double('numericSpeed');
            $table->string('status');
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->double('approxDurationSeconds')->nullable();
            $table->text('errorMessage')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('audio_script_renders')->insert([
            'id' => $ids['render'], 'scriptId' => $ids['script'], 'speed' => '0.85',
            'numericSpeed' => 0.85, 'status' => 'ready', 'audioUrl' => '/audio/script.mp3',
            'timingData' => json_encode([['startTime' => 0, 'endTime' => 800]]),
            'approxDurationSeconds' => 0.8, 'errorMessage' => null,
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
    }
}
