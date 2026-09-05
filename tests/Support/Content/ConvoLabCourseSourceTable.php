<?php

namespace Tests\Support\Content;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

final class ConvoLabCourseSourceTable
{
    public static function create(Builder $schema): void
    {
        $schema->create('Course', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status');
            $table->boolean('isSampleContent');
            $table->boolean('isTestCourse');
            $table->string('nativeLanguage');
            $table->string('targetLanguage');
            $table->integer('maxLessonDurationMinutes');
            $table->string('l1VoiceId');
            $table->string('l1VoiceProvider')->nullable();
            $table->string('jlptLevel')->nullable();
            $table->string('speaker1Gender');
            $table->string('speaker2Gender');
            $table->string('speaker1VoiceId')->nullable();
            $table->string('speaker1VoiceProvider')->nullable();
            $table->string('speaker2VoiceId')->nullable();
            $table->string('speaker2VoiceProvider')->nullable();
            $table->json('scriptJson')->nullable();
            $table->json('scriptUnitsJson')->nullable();
            $table->integer('approxDurationSeconds')->nullable();
            $table->text('audioUrl')->nullable();
            $table->json('timingData')->nullable();
            $table->dateTime('createdAt');
            $table->dateTime('updatedAt');
        });
    }

    public static function seed(Connection $source, array $ids, string $created): void
    {
        $source->table('Course')->insert([
            'id' => $ids['course'], 'userId' => $ids['user'], 'title' => 'Cat course',
            'description' => 'Learn about cats.', 'status' => 'ready', 'isSampleContent' => false,
            'isTestCourse' => false, 'nativeLanguage' => 'en', 'targetLanguage' => 'ja',
            'maxLessonDurationMinutes' => 30, 'l1VoiceId' => 'en-US-Neural2-J',
            'l1VoiceProvider' => 'google', 'jlptLevel' => 'N5', 'speaker1Gender' => 'male',
            'speaker2Gender' => 'female', 'speaker1VoiceId' => 'ja-JP-Neural2-B',
            'speaker1VoiceProvider' => 'google', 'speaker2VoiceId' => 'ja-JP-Neural2-C',
            'speaker2VoiceProvider' => 'google',
            'scriptJson' => json_encode([['_pipelineStage' => 'complete']]),
            'scriptUnitsJson' => json_encode([['type' => 'pause', 'seconds' => 1]]),
            'approxDurationSeconds' => 120, 'audioUrl' => '/audio/course.mp3',
            'timingData' => json_encode([['unitIndex' => 0, 'startTime' => 0, 'endTime' => 1]]),
            'createdAt' => $created, 'updatedAt' => $created,
        ]);
    }
}
