<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Contracts\TransactionInterface;
use Laudis\Neo4j\Databags\DatabaseInfo;
use Laudis\Neo4j\Databags\ResultSummary;
use Laudis\Neo4j\Databags\ServerInfo;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\SummaryCounters;
use Laudis\Neo4j\Enum\ConnectionProtocol;
use Laudis\Neo4j\Enum\QueryTypeEnum;
use Laudis\Neo4j\Types\CypherList;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jConnection;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use Neo4j\Neo4jLaravel\Relations\MatchRelationship;
use Psr\Http\Message\UriInterface;

/**
 * Points the package connection at a client that records every statement and returns
 * empty results, so writer tests never reach a real Neo4j. Reads therefore find
 * nothing: firstOrCreate/updateOrCreate always insert.
 *
 * Expected writes are described as neo4j-laravel calls; the call runs against the same
 * recorder and its statement is compared with what the writer sent.
 */
trait RecordsGraphWrites
{
    /** @var list<array{statement: string, params: array<string, mixed>}> */
    private array $graphWrites = [];

    private function recordGraphWrites(): void
    {
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $statement, iterable $params = []): SummarizedResult {
            $this->graphWrites[] = ['statement' => $statement, 'params' => [...$params]];
            $counters = new SummaryCounters;
            $summary = new ResultSummary(
                $counters,
                new DatabaseInfo('neo4j'),
                new CypherList,
                null,
                null,
                new Statement($statement, $params),
                QueryTypeEnum::fromCounters($counters),
                0,
                0,
                new ServerInfo($this->createMock(UriInterface::class), ConnectionProtocol::BOLT_V5(), 'recording'),
            );

            return new SummarizedResult($summary);
        });

        $client = $this->createMock(ClientInterface::class);
        $client->method('writeTransaction')->willReturnCallback(fn (callable $handler): mixed => $handler($tx));
        $client->method('readTransaction')->willReturnCallback(fn (callable $handler): mixed => $handler($tx));

        $name = Neo4jBoltClient::ELOQUENT_CONNECTION;
        DB::purge($name);
        DB::extend($name, fn (array $config, string $connectionName): Neo4jConnection => new Neo4jConnection($client, 'neo4j', '', $config + ['name' => $connectionName]));
    }

    /**
     * @return list<array{statement: string, params: array<string, mixed>}>
     */
    private function takeGraphWrites(): array
    {
        $recorded = $this->graphWrites;
        $this->graphWrites = [];

        return $recorded;
    }

    private function graph(string $label): Neo4jQueryBuilder
    {
        $query = DB::connection(Neo4jBoltClient::ELOQUENT_CONNECTION)->table($label);
        $this->assertInstanceOf(Neo4jQueryBuilder::class, $query);

        return $query;
    }

    /**
     * Positions of recorded writes that are the same write as $write. With $sameValues
     * false only the kind of write is compared (same labels, relationship and properties).
     *
     * @param  list<array{statement: string, params: array<string, mixed>}>  $recorded
     * @param  Closure(): mixed  $write  one neo4j-laravel write call
     * @return list<int>
     */
    private function writesLike(array $recorded, Closure $write, bool $sameValues = true): array
    {
        $before = count($this->graphWrites);
        $write();
        $expected = array_slice($this->graphWrites, $before);
        $this->graphWrites = array_slice($this->graphWrites, 0, $before);
        $this->assertCount(1, $expected, 'The expected write must run exactly one statement.');

        return array_keys(array_filter(
            $recorded,
            static fn (array $made): bool => $made['statement'] === $expected[0]['statement']
                && (! $sameValues || $made['params'] === $expected[0]['params']),
        ));
    }

    /**
     * @param  list<array{statement: string, params: array<string, mixed>}>  $recorded
     * @param  Closure(): mixed  $write
     */
    private function assertWroteOnce(array $recorded, Closure $write): int
    {
        $positions = $this->writesLike($recorded, $write);
        $this->assertCount(1, $positions);

        return $positions[0];
    }

    /**
     * @param  list<array{statement: string, params: array<string, mixed>}>  $recorded
     * @param  Closure(): mixed  $write
     */
    private function assertNeverWroteKind(array $recorded, Closure $write): void
    {
        $this->assertSame([], $this->writesLike($recorded, $write, sameValues: false));
    }

    /**
     * @param  MatchRelationship<Model, Model>  $relation
     * @param  class-string  $related
     */
    private function assertRelationship(string $type, string $related, MatchRelationship $relation): void
    {
        $this->assertSame($type, $relation->getRelationshipType());
        $this->assertInstanceOf($related, $relation->getRelated());
    }
}
