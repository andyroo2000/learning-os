<?php

namespace Tests\Unit\Courses;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Domain\Courses\Sync\CourseSyncPayload;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CourseSyncPayloadTest extends TestCase
{
    public function test_course_payload_uses_client_facing_resource_keys(): void
    {
        $course = new Course;
        $course->setRawAttributes([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => null,
            'status' => CourseStatus::Ready->value,
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => Carbon::parse('2026-06-04T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
            'deleted_at' => null,
        ], sync: true);

        $payload = CourseSyncPayload::fromCourse($course);

        $expected = [
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => null,
            'status' => 'ready',
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => '2026-06-04T09:14:00.000000Z',
            'updated_at' => '2026-06-04T09:15:00.000000Z',
            'deleted_at' => null,
        ];

        $this->assertSame('courses', CourseSyncPayload::DOMAIN);
        $this->assertSame('course', CourseSyncPayload::RESOURCE_TYPE);
        $this->assertSame($expected, $payload);
        $this->assertArrayNotHasKey('user_id', $payload);
    }

    public function test_soft_deleted_course_payload_serializes_deleted_at(): void
    {
        $course = new Course;
        $course->setRawAttributes([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => 'Core listening and speaking practice.',
            'status' => CourseStatus::Error->value,
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => Carbon::parse('2026-06-04T09:14:00Z'),
            'updated_at' => Carbon::parse('2026-06-04T09:15:00Z'),
            'deleted_at' => Carbon::parse('2026-06-04T09:20:00Z'),
        ], sync: true);

        $payload = CourseSyncPayload::fromCourse($course);

        $this->assertSame([
            'id' => '01k1j8j9m0e4k7r2y8p5w6q3at',
            'title' => 'Spanish Foundations',
            'description' => 'Core listening and speaking practice.',
            'status' => 'error',
            'native_language' => 'en',
            'target_language' => 'es',
            'created_at' => '2026-06-04T09:14:00.000000Z',
            'updated_at' => '2026-06-04T09:15:00.000000Z',
            'deleted_at' => '2026-06-04T09:20:00.000000Z',
        ], $payload);
    }
}
