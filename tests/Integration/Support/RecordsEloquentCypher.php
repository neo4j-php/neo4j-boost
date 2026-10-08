<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

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
use Psr\Http\Message\UriInterface;

/**
 * Points the package Eloquent connection at a client that records every Cypher
 * statement and returns empty results, so writer tests never reach a real Neo4j.
 * Reads therefore find nothing: firstOrCreate/updateOrCreate always insert.
 */
trait RecordsEloquentCypher
{
    /** @var list<array{cypher: string, params: array<string, mixed>}> */
    private array $eloquentCypher = [];

    private function recordEloquentCypher(): void
    {
        $tx = $this->createMock(TransactionInterface::class);
        $tx->method('run')->willReturnCallback(function (string $cypher, iterable $params = []): SummarizedResult {
            $this->eloquentCypher[] = ['cypher' => $cypher, 'params' => [...$params]];
            $counters = new SummaryCounters;
            $summary = new ResultSummary(
                $counters,
                new DatabaseInfo('neo4j'),
                new CypherList,
                null,
                null,
                new Statement($cypher, $params),
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
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    private function takeEloquentCypher(): array
    {
        $recorded = $this->eloquentCypher;
        $this->eloquentCypher = [];

        return $recorded;
    }

    /**
     * Statements whose Cypher contains every needle and whose parameters contain every value.
     *
     * @param  list<array{cypher: string, params: array<string, mixed>}>  $recorded
     * @param  list<string>  $needles
     * @param  list<mixed>  $values
     * @return list<array{cypher: string, params: array<string, mixed>}>
     */
    private function statementsMatching(array $recorded, array $needles, array $values = []): array
    {
        return array_values(array_filter($recorded, static function (array $statement) use ($needles, $values): bool {
            foreach ($needles as $needle) {
                if (! str_contains($statement['cypher'], $needle)) {
                    return false;
                }
            }
            $params = [];
            array_walk_recursive($statement['params'], static function (mixed $param) use (&$params): void {
                $params[] = $param;
            });
            foreach ($values as $value) {
                if (! in_array($value, $params, true)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
