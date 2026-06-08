<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support\Stubs;

use Laudis\Neo4j\Databags\SummarizedResult;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use RuntimeException;

final class UnusedContainerGraphConnection extends ContainerGraphConnection
{
    public function connect(): void {}

    public function run(string $statement, array $parameters = []): SummarizedResult
    {
        throw new RuntimeException('In-memory graph reader does not query Neo4j.');
    }
}
