<?php

namespace Tests\Unit;

use JsonException;
use Tests\TestCase;

class GitHubWorkflowDependencyAuditTest extends TestCase
{
    /** @throws JsonException */
    public function test_ci_audits_the_full_node_lockfile_at_the_high_threshold_before_installing_dependencies(): void
    {
        $package = json_decode(
            file_get_contents(base_path('package.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            'npm audit --package-lock-only --audit-level=high',
            $package['scripts']['audit:dependencies'] ?? null,
        );

        $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));
        $nodeAudit = <<<'YAML'
      - name: Audit Node dependencies
        run: npm run audit:dependencies
YAML;
        $nodeInstall = <<<'YAML'
      - name: Install Node dependencies
        run: npm ci
YAML;
        $phpAudit = <<<'YAML'
      - name: Audit PHP dependencies
        run: composer audit --locked
YAML;

        $this->assertStringContainsString($nodeAudit, $workflow);
        $this->assertStringContainsString($nodeInstall, $workflow);
        $this->assertStringContainsString($phpAudit, $workflow);
        $this->assertLessThan(strpos($workflow, $nodeInstall), strpos($workflow, $nodeAudit));
    }
}
