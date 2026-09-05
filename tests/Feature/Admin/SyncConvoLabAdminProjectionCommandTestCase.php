<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

abstract class SyncConvoLabAdminProjectionCommandTestCase extends TestCase
{
    use RefreshDatabase;

    private string $sourceDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDatabase = storage_path('framework/testing/convolab-admin-'.uniqid().'.sqlite');
        touch($this->sourceDatabase);
        config()->set('database.connections.convolab_admin_test', [
            'driver' => 'sqlite',
            'database' => $this->sourceDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('convolab_admin_test');
        $this->createSourceSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('convolab_admin_test');

        if (isset($this->sourceDatabase) && is_file($this->sourceDatabase)) {
            unlink($this->sourceDatabase);
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    protected function runSync(array $options = []): PendingCommand
    {
        return $this->artisan('admin:sync-convolab', array_merge([
            '--source-connection' => 'convolab_admin_test',
        ], $options));
    }

    protected function createSourceSchema(): void
    {
        Schema::connection('convolab_admin_test')->create('User', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('email');
            $table->string('password')->nullable();
            $table->string('name');
            $table->string('displayName')->nullable();
            $table->string('avatarColor')->nullable();
            $table->text('avatarUrl')->nullable();
            $table->string('role');
            $table->string('preferredStudyLanguage');
            $table->string('preferredNativeLanguage');
            $table->string('proficiencyLevel');
            $table->boolean('onboardingCompleted');
            $table->boolean('seenSampleContentGuide');
            $table->boolean('seenCustomContentGuide');
            $table->boolean('emailVerified');
            $table->timestamp('emailVerifiedAt')->nullable();
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
        Schema::connection('convolab_admin_test')->create('InviteCode', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('code');
            $table->string('usedBy')->nullable();
            $table->timestamp('usedAt')->nullable();
            $table->timestamp('createdAt');
        });
        Schema::connection('convolab_admin_test')->create('SpeakerAvatar', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('filename');
            $table->text('croppedUrl');
            $table->text('originalUrl');
            $table->string('language');
            $table->string('gender');
            $table->string('tone');
            $table->timestamp('createdAt');
            $table->timestamp('updatedAt');
        });
    }

    /** @param array<string, mixed> $attributes */
    protected function insertSourceUser(string $id, array $attributes = []): void
    {
        DB::connection('convolab_admin_test')->table('User')->insert(array_merge([
            'id' => $id,
            'email' => 'user@example.com',
            'password' => null,
            'name' => 'Source User',
            'displayName' => null,
            'avatarColor' => 'indigo',
            'avatarUrl' => null,
            'role' => 'user',
            'preferredStudyLanguage' => 'ja',
            'preferredNativeLanguage' => 'en',
            'proficiencyLevel' => 'beginner',
            'onboardingCompleted' => false,
            'seenSampleContentGuide' => false,
            'seenCustomContentGuide' => false,
            'emailVerified' => false,
            'emailVerifiedAt' => null,
            'createdAt' => '2026-07-20 10:00:00.123',
            'updatedAt' => '2026-07-20 11:00:00.456',
        ], $attributes));
    }

    protected function insertSourceInvite(
        string $id,
        string $code,
        ?string $usedBy = null,
        ?string $usedAt = null,
    ): void {
        DB::connection('convolab_admin_test')->table('InviteCode')->insert([
            'id' => $id,
            'code' => $code,
            'usedBy' => $usedBy,
            'usedAt' => $usedAt,
            'createdAt' => '2026-07-21 10:00:00.123',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function insertSourceAvatar(string $id, array $attributes = []): void
    {
        DB::connection('convolab_admin_test')->table('SpeakerAvatar')->insert(array_merge([
            'id' => $id,
            'filename' => 'ja-female-casual.jpg',
            'croppedUrl' => 'https://storage.example/cropped.jpg',
            'originalUrl' => 'https://storage.example/original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'casual',
            'createdAt' => '2026-07-21 10:00:00.123',
            'updatedAt' => '2026-07-21 11:00:00.456',
        ], $attributes));
    }
}
