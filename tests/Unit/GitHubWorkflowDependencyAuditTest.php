<?php

namespace Tests\Unit;

use JsonException;
use PHPUnit\Framework\TestCase;

class GitHubWorkflowDependencyAuditTest extends TestCase
{
    /** @throws JsonException */
    public function test_ci_audits_the_full_node_lockfile_at_the_high_threshold_before_installing_dependencies(): void
    {
        $package = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/package.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            'npm audit --package-lock-only --audit-level=high',
            $package['scripts']['audit:dependencies'] ?? null,
        );

        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('name: Audit Node dependencies', $workflow);
        $this->assertStringContainsString('name: Install Node dependencies', $workflow);
        $this->assertStringContainsString('name: Audit PHP dependencies', $workflow);

        $nodeAuditPosition = strpos($workflow, 'run: npm run audit:dependencies');
        $nodeInstallPosition = strpos($workflow, 'run: npm ci');
        $phpAuditPosition = strpos($workflow, 'run: composer audit --locked');

        $this->assertIsInt($nodeAuditPosition);
        $this->assertIsInt($nodeInstallPosition);
        $this->assertIsInt($phpAuditPosition);
        $this->assertLessThan($nodeInstallPosition, $nodeAuditPosition);
    }
}
