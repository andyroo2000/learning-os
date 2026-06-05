<?php

namespace Tests\Unit\Resources\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Http\Resources\Courses\CourseResource;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CourseResourceTest extends TestCase
{
    public function test_course_resource_serializes_deleted_at_for_tombstones(): void
    {
        $course = new Course;
        $course->setRawAttributes([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => 'Core listening and speaking practice.',
            'status' => CourseStatus::Ready->value,
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => Carbon::parse('2026-06-04T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
            'deleted_at' => Carbon::parse('2026-06-04T09:20:00Z'),
        ], sync: true);

        $resource = CourseResource::make($course)->resolve();

        $this->assertSame('2026-06-04T09:20:00.000000Z', $resource['deleted_at']);
    }

    public function test_course_resource_defaults_missing_status_to_draft(): void
    {
        $course = new Course;
        $course->setRawAttributes([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => null,
            'status' => null,
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => Carbon::parse('2026-06-04T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $resource = CourseResource::make($course)->resolve();

        $this->assertSame(CourseStatus::Draft->value, $resource['status']);
        $this->assertNull($resource['deleted_at']);
    }
}
