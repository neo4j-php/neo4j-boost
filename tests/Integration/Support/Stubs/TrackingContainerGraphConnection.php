<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support\Stubs;

use Laudis\Neo4j\Databags\SummarizedResult;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;

/**
 * Records the raw Cypher statements the writer still sends over Bolt
 * (container core graph, routes, legacy cleanup) without a database.
 */
final class TrackingContainerGraphConnection extends ContainerGraphConnection
{
    /** @var list<string> */
    private array $statements = [];

    public function connect(): void {}

    public function run(string $statement, array $parameters = []): SummarizedResult
    {
        $this->statements[] = $statement;

        $summary = null;

        return new SummarizedResult($summary, [], []);
    }

    public function ranStatementMatching(string $needle): bool
    {
        foreach ($this->statements as $statement) {
            if (str_contains($statement, $needle)) {
                return true;
            }
        }

        return false;
    }
}
