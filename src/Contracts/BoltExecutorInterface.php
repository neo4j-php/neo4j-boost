<?php

namespace Neo4j\LaravelBoost\Contracts;

use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Enum\QueryTypeEnum;

interface BoltExecutorInterface
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function runRead(string $cypher, array $parameters = []): SummarizedResult;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function runWrite(string $cypher, array $parameters = []): SummarizedResult;

    /**
     * Classify a query using EXPLAIN (same approach as neo4j/mcp).
     *
     * @param  array<string, mixed>  $parameters
     */
    public function getQueryType(string $cypher, array $parameters = []): QueryTypeEnum;
}
