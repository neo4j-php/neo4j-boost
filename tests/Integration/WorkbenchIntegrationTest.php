<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Tests\WorkbenchTestCase;
use Orchestra\Testbench\Concerns\WithWorkbench;

/**
 * Ensures tests use {@see WithWorkbench} + root testbench.yaml
 * (web route discovery), not only plain PHPUnit.
 */
class WorkbenchIntegrationTest extends WorkbenchTestCase
{
    public function test_workbench_web_route_from_testbench_yaml_is_reachable(): void
    {
        $this->get('/')->assertOk();
    }
}
