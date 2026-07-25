<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\PersistUploadedStudyImageAction;
use App\Domain\Study\Exceptions\StudyCardImageValidationException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersistUploadedStudyImageActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
    }

    public function test_direct_callers_cannot_persist_non_image_bytes(): void
    {
        $user = User::factory()->create();

        try {
            app(PersistUploadedStudyImageAction::class)->handle(
                $user->id,
                UploadedFile::fake()->createWithContent('fake.jpg', 'not an image'),
            );
            $this->fail('Expected invalid image bytes to be rejected.');
        } catch (StudyCardImageValidationException $e) {
            $this->assertSame('image', $e->field());
            $this->assertSame(
                'image must be a valid JPEG, PNG, or WebP image.',
                $e->getMessage(),
            );
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_direct_callers_cannot_persist_oversized_images(): void
    {
        $user = User::factory()->create();
        $oversized = $this->jpegBytes()
            .str_repeat("\0", PersistUploadedStudyImageAction::MAX_UPLOAD_BYTES);

        try {
            app(PersistUploadedStudyImageAction::class)->handle(
                $user->id,
                UploadedFile::fake()->createWithContent('large.jpg', $oversized),
            );
            $this->fail('Expected oversized image bytes to be rejected.');
        } catch (StudyCardImageValidationException $e) {
            $this->assertSame('image', $e->field());
            $this->assertSame('image must not exceed 10 MB.', $e->getMessage());
        }

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    private function jpegBytes(): string
    {
        return (string) base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AX//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AX//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z',
            true,
        );
    }
}
