<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static checks on docker-compose.yml — no Docker daemon required.
 *
 * Guards the Step 12 infrastructure contract: a dedicated `scheduler` service
 * that reuses the backend image, runs `schedule:work`, shares the backend
 * environment, and exposes no HTTP port.
 */
class DockerComposeSchedulerTest extends TestCase
{
    private string $compose;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3).'/docker-compose.yml';
        $this->assertFileExists($path, 'docker-compose.yml should be at the repo root');
        $this->compose = (string) file_get_contents($path);
    }

    /**
     * Return the body of a top-level `services:` entry: every line after
     * `  <name>:` up to (but not including) the next line indented two spaces or
     * less (the next service, `volumes:`, etc.).
     */
    private function serviceBlock(string $name): string
    {
        $lines = explode("\n", $this->compose);
        $body = [];
        $inside = false;

        foreach ($lines as $line) {
            if ($inside) {
                $isDeeper = $line === '' || preg_match('/^\s{3,}\S/', $line) === 1 || preg_match('/^\s+#/', $line) === 1;
                if (! $isDeeper) {
                    break;
                }
                $body[] = $line;

                continue;
            }

            if ($line === "  {$name}:") {
                $inside = true;
            }
        }

        $this->assertTrue($inside, "docker-compose.yml should define a `{$name}` service");

        return implode("\n", $body);
    }

    #[Test]
    public function compose_defines_exactly_the_four_expected_services(): void
    {
        // The `services:` section only — indented/blank lines, stopping at the
        // next top-level key (e.g. `volumes:`).
        preg_match('/^services:\n(?<body>(?:[ \t][^\n]*\n|\n)*)/m', $this->compose, $m);
        preg_match_all('/^  ([a-z0-9_-]+):$/m', $m['body'] ?? '', $names);

        sort($names[1]);
        $this->assertSame(['backend', 'frontend', 'postgres', 'scheduler'], $names[1]);
    }

    #[Test]
    public function the_scheduler_service_runs_schedule_work(): void
    {
        $block = $this->serviceBlock('scheduler');

        $this->assertMatchesRegularExpression(
            '/command:\s*\[?\s*["\']?php["\']?,?\s*["\']?artisan["\']?,?\s*["\']?schedule:work["\']?/',
            $block,
            'scheduler should run `php artisan schedule:work`',
        );
    }

    #[Test]
    public function the_scheduler_reuses_the_backend_image(): void
    {
        $block = $this->serviceBlock('scheduler');

        // Either an explicit backend Dockerfile reference or the shared build anchor.
        $this->assertMatchesRegularExpression(
            '/build:\s*(\*backend-build|[\s\S]*?docker\/backend\/Dockerfile)/',
            $block,
            'scheduler should build from the same image as backend',
        );

        // No second Laravel install / separate build context.
        $this->assertStringNotContainsString('docker/scheduler', $this->compose);
    }

    #[Test]
    public function the_scheduler_shares_the_backend_environment(): void
    {
        $backend = $this->serviceBlock('backend');
        $scheduler = $this->serviceBlock('scheduler');

        // Both pull their env from the same anchor — DB creds declared once.
        $this->assertStringContainsString('environment: *backend-env', $backend);
        $this->assertStringContainsString('environment: *backend-env', $scheduler);

        $this->assertMatchesRegularExpression('/x-backend-env:\s*&backend-env/', $this->compose);
        $this->assertMatchesRegularExpression('/&backend-env\n(?:.*\n)*?\s+DB_HOST:\s*postgres/', $this->compose);
    }

    #[Test]
    public function the_scheduler_can_reach_the_application_source_and_database(): void
    {
        $scheduler = $this->serviceBlock('scheduler');

        // Bind-mounted app source (shared with backend) + DB dependency.
        $this->assertStringContainsString('volumes: *backend-volumes', $scheduler);
        $this->assertMatchesRegularExpression('/x-backend-volumes:.*\n\s*-\s*\.\/backend:\/var\/www\/html/', $this->compose);
        $this->assertMatchesRegularExpression('/depends_on:\n(?:.*\n)*?\s+postgres:\n\s+condition:\s*service_healthy/', $scheduler);
    }

    #[Test]
    public function the_scheduler_exposes_no_host_port(): void
    {
        $this->assertStringNotContainsString('ports:', $this->serviceBlock('scheduler'));
    }

    #[Test]
    public function the_scheduler_restarts_if_it_stops(): void
    {
        $this->assertStringContainsString('restart: unless-stopped', $this->serviceBlock('scheduler'));
    }
}
