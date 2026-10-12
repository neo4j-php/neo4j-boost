<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support\Stubs;

use Laudis\Neo4j\Databags\SummarizedResult;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;

/**
 * Accepts the constraint statements the writer sends over Bolt without a database.
 */
final class TrackingContainerGraphConnection extends ContainerGraphConnection
{
    public function connect(): void {}

    public function run(string $statement, array $parameters = []): SummarizedResult
    {
        $summary = null;

        return new SummarizedResult($summary, [], []);
    }
}
