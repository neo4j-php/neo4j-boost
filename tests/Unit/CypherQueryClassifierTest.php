<?php

namespace Neo4j\LaravelBoost\Tests\Unit;

use Laudis\Neo4j\Enum\QueryTypeEnum;
use Neo4j\LaravelBoost\Contracts\BoltExecutorInterface;
use Neo4j\LaravelBoost\Support\CypherQueryClassifier;
use Neo4j\LaravelBoost\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CypherQueryClassifierTest extends TestCase
{
    private BoltExecutorInterface&MockObject $executor;

    private CypherQueryClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->executor = $this->createMock(BoltExecutorInterface::class);
        $this->classifier = new CypherQueryClassifier($this->executor);
    }

    public function test_rejects_create_before_explain_round_trip(): void
    {
        $this->executor->expects($this->never())->method('getQueryType');

        $this->assertFalse($this->classifier->isReadOnly('CREATE (n:Test)'));
    }

    public function test_allows_match_after_explain_confirms_read_only(): void
    {
        $this->executor
            ->expects($this->once())
            ->method('getQueryType')
            ->willReturn(QueryTypeEnum::READ_ONLY());

        $this->assertTrue($this->classifier->isReadOnly('MATCH (n) RETURN n LIMIT 1'));
    }
}
