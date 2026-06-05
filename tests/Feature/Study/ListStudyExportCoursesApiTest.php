<?php

namespace Tests\Feature\Study;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListStudyExportCoursesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/study/export/courses')->assertUnauthorized();
    }

    public function test_index_returns_current_courses_for_the_authenticated_user(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $firstCourse = Course::factory()->draft()->for($user)->create([
            'title' => 'English to Japanese',
            'description' => 'Travel phrases and short conversations.',
            'native_language' => 'en',
            'target_language' => 'ja',
        ]);
        $secondCourse = Course::factory()->ready()->for($user)->create([
            'title' => 'Spanish Listening',
            'description' => null,
            'native_language' => 'en',
            'target_language' => 'es',
        ]);
        $deletedCourse = Course::factory()->for($user)->create([
            'title' => 'Deleted Course',
        ]);
        $otherCourse = Course::factory()->for($otherUser)->create([
            'title' => 'Hidden Course',
        ]);

        $deletedCourse->delete();

        $this->getJson('/api/study/export/courses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $firstCourse->id)
            ->assertJsonPath('data.0.title', 'English to Japanese')
            ->assertJsonPath('data.0.description', 'Travel phrases and short conversations.')
            ->assertJsonPath('data.0.status', CourseStatus::Draft->value)
            ->assertJsonPath('data.0.native_language', 'en')
            ->assertJsonPath('data.0.target_language', 'ja')
            ->assertJsonPath('data.0.deleted_at', null)
            ->assertJsonPath('data.1.id', $secondCourse->id)
            ->assertJsonPath('data.1.title', 'Spanish Listening')
            ->assertJsonPath('data.1.description', null)
            ->assertJsonPath('data.1.status', CourseStatus::Ready->value)
            ->assertJsonMissing([
                'id' => $deletedCourse->id,
            ])
            ->assertJsonMissing([
                'id' => $otherCourse->id,
            ])
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'native_language',
                        'target_language',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ],
                ],
            ]);
    }
}
