<?php

namespace Database\Factories;

use App\Domain\Courses\Enums\CourseStatus;
use App\Domain\Courses\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(4, true),
            'description' => fake()->optional()->sentence(),
            'status' => CourseStatus::Draft,
            'native_language' => 'en',
            'target_language' => 'ja',
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Draft,
        ]);
    }

    public function generating(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Generating,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Ready,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn (): array => [
            'status' => CourseStatus::Error,
        ]);
    }
}
