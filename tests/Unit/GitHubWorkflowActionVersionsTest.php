<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class GitHubWorkflowActionVersionsTest extends TestCase
{
    public function test_workflow_actions_use_reviewed_major_versions(): void
    {
        $expectedMajors = [
            'actions/cache' => 5,
            'actions/checkout' => 5,
            'actions/download-artifact' => 8,
            'actions/setup-node' => 5,
            'actions/upload-artifact' => 7,
            'anthropics/claude-code-action' => 1,
            'docker/build-push-action' => 6,
            'docker/login-action' => 3,
            'docker/metadata-action' => 5,
            'docker/setup-buildx-action' => 3,
            'shivammathur/setup-php' => 2,
        ];
        $foundActions = [];
        $workflowDirectory = dirname(__DIR__, 2).'/.github/workflows';
        $workflowPaths = array_merge(
            glob($workflowDirectory.'/*.yml') ?: [],
            glob($workflowDirectory.'/*.yaml') ?: [],
        );

        $this->assertNotEmpty($workflowPaths);

        foreach ($workflowPaths as $workflowPath) {
            $workflow = file_get_contents($workflowPath);

            $this->assertIsString($workflow);
            preg_match_all(
                '/^\s*(?:-\s+)?uses:\s+([^\s#]+)(?:\s+#.*)?$/im',
                $workflow,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as [, $reference]) {
                if (str_starts_with($reference, './')) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/^([a-z0-9_.-]+\/[a-z0-9_.-]+)@v(\d+)$/i',
                    $reference,
                    sprintf('%s uses unsupported external action reference %s.', basename($workflowPath), $reference),
                );
                preg_match('/^([a-z0-9_.-]+\/[a-z0-9_.-]+)@v(\d+)$/i', $reference, $referenceParts);
                [, $action, $major] = $referenceParts;

                $this->assertArrayHasKey(
                    $action,
                    $expectedMajors,
                    sprintf('%s uses unreviewed action %s.', basename($workflowPath), $action),
                );
                $this->assertSame(
                    $expectedMajors[$action],
                    (int) $major,
                    sprintf('%s must use %s@v%d.', basename($workflowPath), $action, $expectedMajors[$action]),
                );
                $foundActions[$action] = true;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($expectedMajors), array_keys($foundActions));
    }
}
