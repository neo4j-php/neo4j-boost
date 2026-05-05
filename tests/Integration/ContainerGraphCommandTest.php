<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Neo4j\LaravelBoost\Tests\TestCase;

class ContainerGraphCommandTest extends TestCase
{
    public function test_container_graph_dry_run_exits_successfully(): void
    {
        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);
    }
}
