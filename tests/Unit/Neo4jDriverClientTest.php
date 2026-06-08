<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Enum\QueryTypeEnum;
use Laudis\Neo4j\Types\CypherMap;
use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;
use Neo4j\LaravelBoost\Neo4jDriverClient;
use Neo4j\LaravelBoost\Support\CypherQueryClassifier;
use Neo4j\LaravelBoost\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class Neo4jDriverClientTest extends TestCase
{
    private BoltExecutorInterface&MockObject $executor;

    private Neo4jDriverClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->createMock(BoltExecutorInterface::class);
        $this->client = new Neo4jDriverClient($this->executor);
    }

    public function test_read_cypher_rejects_write_query(): void
    {
        $this->executor->expects($this->never())->method('getQueryType');

        $result = $this->client->callTool('read-cypher', [
            'query' => 'CREATE (n)',
            'params' => new \stdClass,
        ]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString(
            CypherQueryClassifier::READ_REJECTION_MESSAGE,
            $result['content'][0]['text'] ?? '',
        );
    }

    public function test_read_cypher_returns_rows_for_read_only_query(): void
    {
        $this->executor
            ->method('getQueryType')
            ->willReturn(QueryTypeEnum::READ_ONLY());

        $record = new CypherMap(['name' => 'Alice']);
        $resultSet = $this->createSummarizedResult([$record]);

        $this->executor
            ->expects($this->once())
            ->method('runRead')
            ->with('MATCH (n) RETURN n LIMIT 1', [])
            ->willReturn($resultSet);

        $result = $this->client->callTool('read-cypher', [
            'query' => 'MATCH (n) RETURN n LIMIT 1',
        ]);

        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('Alice', $result['content'][0]['text'] ?? '');
    }

    public function test_write_cypher_executes_without_classification(): void
    {
        $record = new CypherMap(['created' => 1]);
        $resultSet = $this->createSummarizedResult([$record]);

        $this->executor
            ->expects($this->never())
            ->method('getQueryType');

        $this->executor
            ->expects($this->once())
            ->method('runWrite')
            ->with('CREATE (n:Test {id: 1})', [])
            ->willReturn($resultSet);

        $result = $this->client->callTool('write-cypher', [
            'query' => 'CREATE (n:Test {id: 1})',
        ]);

        $this->assertFalse($result['isError']);
    }

    public function test_list_gds_procedures_returns_clear_error_when_gds_missing(): void
    {
        $this->executor
            ->expects($this->once())
            ->method('runRead')
            ->willThrowException(new \RuntimeException('Unknown procedure gds.list'));

        $result = $this->client->callTool('list-gds-procedures', []);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('Graph Data Science (GDS)', $result['content'][0]['text'] ?? '');
    }

    /**
     * @param  array<int, CypherMap<string, mixed>>  $records
     */
    private function createSummarizedResult(array $records): SummarizedResult
    {
        return new SummarizedResult(
            $summary,
            $records,
            array_keys($records[0]->toArray()),
        );
    }
}
