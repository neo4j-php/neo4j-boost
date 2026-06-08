<?php

namespace Neo4j\LaravelBoost;

use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Enum\QueryTypeEnum;
use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use RuntimeException;

final class Neo4jBoltExecutor implements BoltExecutorInterface
{
    public function __construct(
        private Neo4jBoltClient $bolt,
    ) {}

    public function runRead(string $cypher, array $parameters = []): SummarizedResult
    {
        return $this->bolt->mcpDriverClient()->run($cypher, $parameters, $this->bolt->driverAlias());
    }

    public function runWrite(string $cypher, array $parameters = []): SummarizedResult
    {
        return $this->bolt->mcpDriverClient()->run($cypher, $parameters, $this->bolt->driverAlias());
    }

    public function getQueryType(string $cypher, array $parameters = []): QueryTypeEnum
    {
        $explained = 'EXPLAIN '.$cypher;
        $result = $this->bolt->mcpDriverClient()->run($explained, $parameters, $this->bolt->driverAlias());

        try {
            return $result->getSummary()->getQueryType();
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to classify Cypher query: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeParameters(mixed $params): array
    {
        if ($params instanceof \stdClass) {
            return (array) $params;
        }

        if (is_array($params)) {
            return $params;
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function summarizedResultToRows(SummarizedResult $result): array
    {
        $rows = [];
        foreach ($result as $record) {
            $rows[] = $record->toArray();
        }

        return $rows;
    }
}
