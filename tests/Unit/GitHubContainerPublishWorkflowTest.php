<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GitHubContainerPublishWorkflowTest extends TestCase
{
    public function test_container_smoke_uses_and_exercises_a_database_queue_worker(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/container.yml');

        $this->assertIsString($workflow);
        $this->assertStringNotContainsString('--env QUEUE_CONNECTION=sync', $workflow);
        $this->assertStringContainsString('name: Exercise database queue worker', $workflow);
        $this->assertStringContainsString('SendPasswordResetLink::dispatch', $workflow);
        $this->assertStringContainsString('php artisan queue:work database', $workflow);
        $this->assertStringContainsString('--stop-when-empty', $workflow);
        $this->assertStringContainsString('Queue worker left pending or failed jobs:', $workflow);
    }

    public function test_manual_container_publishing_is_opt_in_main_only_and_least_privilege(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/container.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString(<<<'YAML'
  workflow_dispatch:
    inputs:
      publish:
        description: Publish the tested image when dispatching from main
        required: false
        type: boolean
        default: false
YAML, $workflow);
        $this->assertStringContainsString(
            "if: (github.event_name == 'push' && github.ref == 'refs/heads/main') || (github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main' && inputs.publish)",
            $workflow,
        );

        preg_match('/^  publish:\n(?<job>.*)\z/ms', $workflow, $matches);

        $this->assertArrayHasKey('job', $matches);
        $this->assertStringContainsString("permissions:\n      contents: read\n      packages: write", $matches['job']);
        $this->assertSame(1, substr_count($workflow, 'packages: write'));
    }
}
