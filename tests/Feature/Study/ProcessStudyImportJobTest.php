<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Jobs\ProcessStudyImportJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class ProcessStudyImportJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_has_a_visible_retry_and_uniqueness_envelope(): void
    {
        $importJobId = strtolower((string) str()->ulid());
        $job = new ProcessStudyImportJob(strtoupper($importJobId));

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(4, $job->tries);
        $this->assertSame([10, 30, 60], $job->backoff());
        $this->assertSame($importJobId, $job->uniqueId());
    }

    public function test_handle_delegates_to_the_import_processor(): void
    {
        $importJobId = strtolower((string) str()->ulid());
        $processor = $this->mock(ProcessStudyImportJobAction::class);

        $processor
            ->expects('handle')
            ->with($importJobId)
            ->andReturn(null);

        (new ProcessStudyImportJob(strtoupper($importJobId)))->handle($processor);
    }

    public function test_failed_marks_pending_imports_failed_and_normalizes_ids(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $importJob = StudyImportJob::factory()->create();

        (new ProcessStudyImportJob(strtoupper($importJob->id)))
            ->failed(new RuntimeException('Worker infrastructure failed.'));

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Failed, $importJob->status);
        $this->assertSame(ProcessStudyImportJob::EXHAUSTED_ERROR_MESSAGE, $importJob->error_message);
        $this->assertSame(now()->toJSON(), $importJob->completed_at?->toJSON());
    }

    public function test_failed_marks_processing_imports_failed(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $importJob = StudyImportJob::factory()->processing()->create([
            'started_at' => now()->subMinute(),
        ]);

        (new ProcessStudyImportJob($importJob->id))
            ->failed(new RuntimeException('Worker infrastructure failed.'));

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Failed, $importJob->status);
        $this->assertSame(ProcessStudyImportJob::EXHAUSTED_ERROR_MESSAGE, $importJob->error_message);
        $this->assertSame(now()->toJSON(), $importJob->completed_at?->toJSON());
    }

    public function test_failed_is_idempotent_for_terminal_imports(): void
    {
        Carbon::setTestNow('2026-06-07 12:00:00');
        $importJob = StudyImportJob::factory()->create();
        $job = new ProcessStudyImportJob($importJob->id);

        $job->failed(new RuntimeException('First failure.'));

        Carbon::setTestNow('2026-06-07 12:05:00');
        (new ProcessStudyImportJob($importJob->id))
            ->failed(new RuntimeException('Second failure.'));

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Failed, $importJob->status);
        $this->assertSame(ProcessStudyImportJob::EXHAUSTED_ERROR_MESSAGE, $importJob->error_message);
        $this->assertSame('2026-06-07T12:00:00.000000Z', $importJob->completed_at?->toJSON());
    }

    public function test_failed_reports_and_swallows_failure_hook_write_errors(): void
    {
        Exceptions::fake();
        Carbon::setTestNow('2026-06-07 12:00:00');
        $importJob = StudyImportJob::factory()->create();
        $eventName = $this->studyImportUpdatingEventName();
        $originalListeners = $this->rawEventListeners($eventName);

        // Registered only around failed() so this test targets the terminal-write path.
        Event::listen($eventName, static function (): void {
            throw new RuntimeException('Terminal import write failed.');
        });

        try {
            (new ProcessStudyImportJob($importJob->id))
                ->failed(new RuntimeException('Worker infrastructure failed.'));
        } finally {
            $this->restoreRawEventListeners($eventName, $originalListeners);
        }

        $importJob->refresh();

        $this->assertSame(StudyImportStatus::Pending, $importJob->status);
        $this->assertNull($importJob->error_message);
        $this->assertNull($importJob->completed_at);
        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage() === 'Terminal import write failed.',
        );
        Exceptions::assertReportedCount(1);
    }

    private function studyImportUpdatingEventName(): string
    {
        return 'eloquent.updating: '.StudyImportJob::class;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function rawEventListeners(string $eventName): ?array
    {
        $listeners = $this->eventListenersProperty()->getValue(Event::getFacadeRoot());

        return is_array($listeners) && array_key_exists($eventName, $listeners)
            ? $listeners[$eventName]
            : null;
    }

    /**
     * @param  array<int, mixed>|null  $originalListeners
     */
    private function restoreRawEventListeners(string $eventName, ?array $originalListeners): void
    {
        $dispatcher = Event::getFacadeRoot();
        $property = $this->eventListenersProperty();
        $listeners = $property->getValue($dispatcher);

        if (! is_array($listeners)) {
            return;
        }

        if ($originalListeners === null) {
            unset($listeners[$eventName]);
        } else {
            $listeners[$eventName] = $originalListeners;
        }

        $property->setValue($dispatcher, $listeners);
    }

    private function eventListenersProperty(): ReflectionProperty
    {
        return new ReflectionProperty(Event::getFacadeRoot(), 'listeners');
    }

    public function test_failed_ignores_missing_and_terminal_imports(): void
    {
        $completed = StudyImportJob::factory()->completed()->create([
            'completed_at' => now()->subHour(),
            'error_message' => null,
        ]);
        $failed = StudyImportJob::factory()->failed()->create([
            'completed_at' => now()->subHour(),
            'error_message' => 'Already failed.',
        ]);

        (new ProcessStudyImportJob(strtolower((string) str()->ulid())))
            ->failed(new RuntimeException('Ignored.'));
        (new ProcessStudyImportJob($completed->id))
            ->failed(new RuntimeException('Ignored.'));
        (new ProcessStudyImportJob($failed->id))
            ->failed(new RuntimeException('Ignored.'));

        $completed->refresh();
        $failed->refresh();

        $this->assertSame(StudyImportStatus::Completed, $completed->status);
        $this->assertNull($completed->error_message);
        $this->assertSame(StudyImportStatus::Failed, $failed->status);
        $this->assertSame('Already failed.', $failed->error_message);
    }
}
