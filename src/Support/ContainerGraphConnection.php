<?php

namespace Neo4j\LaravelBoost\Support;

use Laudis\Neo4j\Databags\SummarizedResult;

class ContainerGraphConnection
{
    public function __construct(
        private ?Neo4jBoltClient $bolt = null,
    ) {}

    public function connect(): void
    {
        $bolt = $this->bolt();
        $bolt->client()->verifyConnectivity($bolt->driverAlias());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function run(string $statement, array $parameters = []): SummarizedResult
    {
        $bolt = $this->bolt();

        return $bolt->client()->run(
            $statement,
            $parameters,
            $bolt->driverAlias(),
        );
    }

    private function bolt(): Neo4jBoltClient
    {
        return $this->bolt ??= app(Neo4jBoltClient::class);
    }

    /**
     * @return null|array{uri: string, user: string, password: string}
     */
    public static function parseDsnToConnection(string $dsn): ?array
    {
        $parts = parse_url($dsn);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) && $parts['user'] !== '') {
            $user = rawurldecode($parts['user']);
        } else {
            $user = (string) config('neo4j-boost.container_graph.username');
        }
        if (array_key_exists('pass', $parts) && (string) $parts['pass'] !== '') {
            $password = rawurldecode((string) $parts['pass']);
        } else {
            $password = (string) config('neo4j-boost.container_graph.password');
        }

        $uri = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $uri .= ':'.(int) $parts['port'];
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $uri .= $parts['path'];
        }
        if (isset($parts['query'])) {
            $uri .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $uri .= '#'.$parts['fragment'];
        }

        return [
            'uri' => $uri,
            'user' => $user,
            'password' => $password,
        ];
    }
}
