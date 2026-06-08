<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\Support\CypherQueryClassifier;
use Neo4j\LaravelBoost\Support\McpToolResult;
use Throwable;

/**
 * Executes Neo4j MCP tools in-process via the Bolt driver (laudis/neo4j-php-client).
 */
final class Neo4jDriverClient implements Neo4jMcpClientInterface
{
    private const SCHEMA_QUERY = <<<'CYPHER'
CALL apoc.meta.schema({sample: $sampleSize})
YIELD value
UNWIND keys(value) AS key
WITH key, value[key] AS value
RETURN key, value { .properties, .type, .relationships } AS value
CYPHER;

    private const GDS_PROCEDURES_QUERY = <<<'CYPHER'
CALL gds.list() YIELD name, description, signature, type
WHERE type = "procedure"
  AND name CONTAINS "stream"
  AND NOT (name CONTAINS "estimate")
RETURN name, description, signature, type
CYPHER;

    private CypherQueryClassifier $classifier;

    public function __construct(
        private BoltExecutorInterface $executor,
    ) {
        $this->classifier = new CypherQueryClassifier($executor);
    }

    public function callTool(string $toolName, array $arguments = []): array
    {
        return match ($toolName) {
            'get-schema' => $this->getSchema(),
            'read-cypher' => $this->readCypher($arguments),
            'write-cypher' => $this->writeCypher($arguments),
            'list-gds-procedures' => $this->listGdsProcedures(),
            default => McpToolResult::error('Unknown Neo4j tool: '.$toolName),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchema(): array
    {
        $sampleSize = (int) config('neo4j-boost.bolt.schema_sample_size', 100);

        try {
            $result = $this->executor->runRead(self::SCHEMA_QUERY, ['sampleSize' => $sampleSize]);
            $rows = Neo4jBoltExecutor::summarizedResultToRows($result);

            if ($rows === []) {
                return McpToolResult::text(
                    'The get-schema tool executed successfully; however, since the Neo4j instance contains no data, no schema information was returned.'
                );
            }

            return McpToolResult::jsonRows($rows);
        } catch (Throwable $e) {
            if ($this->isApocMissing($e)) {
                return $this->getSchemaWithoutApoc();
            }

            return McpToolResult::error($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getSchemaWithoutApoc(): array
    {
        try {
            $labels = Neo4jBoltExecutor::summarizedResultToRows(
                $this->executor->runRead('CALL db.labels() YIELD label RETURN collect(label) AS labels')
            );
            $relationshipTypes = Neo4jBoltExecutor::summarizedResultToRows(
                $this->executor->runRead('CALL db.relationshipTypes() YIELD relationshipType RETURN collect(relationshipType) AS relationshipTypes')
            );
            $propertyKeys = Neo4jBoltExecutor::summarizedResultToRows(
                $this->executor->runRead('CALL db.propertyKeys() YIELD propertyKey RETURN collect(propertyKey) AS propertyKeys')
            );

            return McpToolResult::jsonRows([[
                'note' => 'APOC is not available; returning catalog metadata only. Install APOC for MCP-compatible schema output (apoc.meta.schema).',
                'labels' => $labels[0]['labels'] ?? [],
                'relationshipTypes' => $relationshipTypes[0]['relationshipTypes'] ?? [],
                'propertyKeys' => $propertyKeys[0]['propertyKeys'] ?? [],
            ]]);
        } catch (Throwable $e) {
            return McpToolResult::error(
                'get-schema requires APOC (apoc.meta.schema) or catalog procedures (db.labels, db.relationshipTypes). '.$e->getMessage()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function readCypher(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return McpToolResult::error('Query parameter is required and cannot be empty');
        }

        $params = Neo4jBoltExecutor::normalizeParameters($arguments['params'] ?? []);

        try {
            if (! $this->classifier->isReadOnly($query)) {
                return McpToolResult::error(CypherQueryClassifier::READ_REJECTION_MESSAGE);
            }

            $result = $this->executor->runRead($query, $params);

            return McpToolResult::jsonRows(Neo4jBoltExecutor::summarizedResultToRows($result));
        } catch (Throwable $e) {
            return McpToolResult::error($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function writeCypher(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return McpToolResult::error('Query parameter is required and cannot be empty');
        }

        $params = Neo4jBoltExecutor::normalizeParameters($arguments['params'] ?? []);

        try {
            $result = $this->executor->runWrite($query, $params);

            return McpToolResult::jsonRows(Neo4jBoltExecutor::summarizedResultToRows($result));
        } catch (Throwable $e) {
            return McpToolResult::error($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function listGdsProcedures(): array
    {
        try {
            $result = $this->executor->runRead(self::GDS_PROCEDURES_QUERY);

            return McpToolResult::jsonRows(Neo4jBoltExecutor::summarizedResultToRows($result));
        } catch (Throwable $e) {
            return McpToolResult::error(
                'Failed to execute list-gds-procedure query: '.$e->getMessage()
                .'. Ensure that the Graph Data Science (GDS) library is installed and properly configured in your Neo4j database.'
            );
        }
    }

    private function isApocMissing(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'apoc')
            || str_contains($message, 'unknown function')
            || str_contains($message, 'procedure')
            || str_contains($message, 'no procedure');
    }
}
