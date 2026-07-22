<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\RecropAdminSpeakerAvatarAction;
use App\Domain\Admin\Actions\UploadAdminSpeakerAvatarAction;
use App\Domain\Admin\Actions\UploadAdminUserAvatarAction;
use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Data\ProcessedAdminAvatarImage;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Media\Contracts\StaticMediaObjectWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminAvatarActionsTest extends TestCase
{
    use RefreshDatabase;

    private const CROP = ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('static_media.gcs.bucket', 'convolab-storage');
    }

    public function test_speaker_upload_cleans_up_all_known_objects_when_the_original_upload_fails(): void
    {
        $existing = $this->insertAvatar();
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $croppedPath = null;
        $originalPath = null;
        $writer->shouldReceive('putPublic')->once()
            ->with(Mockery::pattern('#-ja-female-casual\.jpg$#'), 'cropped-jpeg', 'image/jpeg')
            ->ordered()
            ->andReturnUsing(function (string $path) use (&$croppedPath): void {
                $croppedPath = $path;
            });
        $writer->shouldReceive('putPublic')->once()
            ->with(Mockery::pattern('#-original-ja-female-casual\.png$#'), 'source-image', 'image/png')
            ->ordered()
            ->andReturnUsing(function (string $path) use (&$originalPath): never {
                $originalPath = $path;

                throw new RuntimeException('upload failed');
            });
        $writer->shouldReceive('delete')->once()->with(Mockery::on(
            function (string $path) use (&$originalPath): bool {
                return $originalPath !== null && $path === $originalPath;
            },
        ));
        $writer->shouldReceive('delete')->once()->with(Mockery::on(
            function (string $path) use (&$croppedPath): bool {
                return $croppedPath !== null && $path === $croppedPath;
            },
        ));

        try {
            app(UploadAdminSpeakerAvatarAction::class)->handle(
                'ja-female-casual.jpg',
                'source-image',
                AdminAvatarCropArea::from(self::CROP),
            );
            $this->fail('Expected the original upload to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('upload failed', $exception->getMessage());
        }

        $existing->refresh();
        $this->assertSame('https://storage.example/cropped.jpg', $existing->cropped_url);
        $this->assertSame('https://storage.example/original.jpg', $existing->original_url);
    }

    public function test_speaker_recrop_cleans_up_when_the_original_changes_during_processing(): void
    {
        $avatar = $this->insertAvatar([
            'original_url' => 'https://storage.googleapis.com/convolab-storage/avatars/speakers/original.jpg',
        ]);
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $writer->shouldReceive('read')->once()->andReturnUsing(function () use ($avatar): string {
            DB::table('admin_speaker_avatars')->where('id', $avatar->id)->update([
                'original_url' => 'https://storage.googleapis.com/convolab-storage/avatars/speakers/replaced.jpg',
            ]);

            return 'source-image';
        });
        $storedPath = null;
        $writer->shouldReceive('putPublic')->once()->andReturnUsing(function (string $path) use (&$storedPath): void {
            $storedPath = $path;
        });
        $writer->shouldReceive('delete')->once()->with(Mockery::on(
            function (string $path) use (&$storedPath): bool {
                return $storedPath !== null && $path === $storedPath;
            },
        ));

        try {
            app(RecropAdminSpeakerAvatarAction::class)->handle($avatar->filename, AdminAvatarCropArea::from(self::CROP));
            $this->fail('Expected the stale original to be rejected.');
        } catch (AdminMutationException $exception) {
            $this->assertSame(409, $exception->status());
        }
    }

    public function test_speaker_recrop_rejects_untrusted_original_urls_before_storage_reads(): void
    {
        $avatar = $this->insertAvatar(['original_url' => 'https://example.com/original.jpg']);
        $this->mockProcessor()->shouldNotReceive('process');
        $this->mockWriter()->shouldNotReceive('read', 'putPublic', 'delete');

        $this->expectException(RuntimeException::class);
        app(RecropAdminSpeakerAvatarAction::class)->handle($avatar->filename, AdminAvatarCropArea::from(self::CROP));
    }

    #[DataProvider('missingUserIdProvider')]
    public function test_missing_or_malformed_user_is_rejected_before_image_or_storage_side_effects(
        string $convoLabUserId,
    ): void {
        $this->mockProcessor()->shouldNotReceive('process');
        $this->mockWriter()->shouldNotReceive('putPublic', 'read', 'delete');

        try {
            app(UploadAdminUserAvatarAction::class)->handle(
                $convoLabUserId,
                'source-image',
                AdminAvatarCropArea::from(self::CROP),
            );
            $this->fail('Expected the missing user to be rejected.');
        } catch (AdminMutationException $exception) {
            $this->assertSame(404, $exception->status());
            $this->assertSame('User not found', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function missingUserIdProvider(): iterable
    {
        yield 'valid but missing' => ['01a1f0db-a4c8-4caa-adf6-d3801fdd7061'];
        yield 'malformed' => ['not-a-uuid'];
    }

    public function test_crop_contract_rejects_invalid_direct_caller_shapes(): void
    {
        foreach ([
            null,
            array_merge(self::CROP, ['width' => 0]),
            array_merge(self::CROP, ['height' => -1]),
            array_merge(self::CROP, ['x' => '10']),
            [10, 20, 100, 100],
        ] as $crop) {
            try {
                AdminAvatarCropArea::from($crop);
                $this->fail('Expected invalid crop data to be rejected.');
            } catch (AdminMutationException $exception) {
                $this->assertSame('Invalid crop area', $exception->getMessage());
            }
        }
    }

    public function test_invalid_upload_filename_is_rejected_before_image_or_storage_side_effects(): void
    {
        $this->mockProcessor()->shouldNotReceive('process');
        $this->mockWriter()->shouldNotReceive('putPublic', 'read', 'delete');

        $this->expectException(AdminMutationException::class);
        $this->expectExceptionMessage('Invalid avatar filename format');
        app(UploadAdminSpeakerAvatarAction::class)->handle(
            'ja-female-casual.gif',
            'source-image',
            AdminAvatarCropArea::from(self::CROP),
        );
    }

    private function mockProcessor(): AdminAvatarImageProcessor&MockInterface
    {
        $processor = Mockery::mock(AdminAvatarImageProcessor::class);
        $processor->shouldReceive('process')->byDefault()
            ->with(Mockery::type('string'), Mockery::type(AdminAvatarCropArea::class))
            ->andReturn(new ProcessedAdminAvatarImage('cropped-jpeg', 'image/png', 'png'));
        $this->app->instance(AdminAvatarImageProcessor::class, $processor);

        return $processor;
    }

    private function mockWriter(): StaticMediaObjectWriter&MockInterface
    {
        $writer = Mockery::mock(StaticMediaObjectWriter::class);
        $this->app->instance(StaticMediaObjectWriter::class, $writer);

        return $writer;
    }

    /** @param array<string, mixed> $attributes */
    private function insertAvatar(array $attributes = []): AdminSpeakerAvatar
    {
        $id = (string) Str::uuid();
        DB::table('admin_speaker_avatars')->insert(array_merge([
            'id' => $id,
            'filename' => 'ja-female-casual.jpg',
            'cropped_url' => 'https://storage.example/cropped.jpg',
            'original_url' => 'https://storage.example/original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'casual',
            'source_system' => 'convolab',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return AdminSpeakerAvatar::query()->findOrFail($id);
    }
}
