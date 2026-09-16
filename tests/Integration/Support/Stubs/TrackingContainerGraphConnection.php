<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support\Stubs;

use Laudis\Neo4j\Databags\SummarizedResult;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;

/**
 * Records replaceable relationship edges by applying Cypher semantics from the
 * statement text (Job USES_CONNECTION, AuthGuard USES_PROVIDER).
 */
final class TrackingContainerGraphConnection extends ContainerGraphConnection
{
    /** @var array<string, list<string>> job key => connected queue connection keys */
    private array $usesConnections = [];

    /** @var array<string, list<string>> auth guard key => provider keys */
    private array $usesProviders = [];

    /** @var list<string> */
    private array $statements = [];

    public function connect(): void {}

    public function run(string $statement, array $parameters = []): SummarizedResult
    {
        $this->statements[] = $statement;

        if (isset($parameters['rows']) && is_array($parameters['rows'])) {
            if (str_contains($statement, ':Job')) {
                $this->applyJobUsesConnectionSemantics($statement, $parameters['rows']);
            }

            if (str_contains($statement, ':AuthGuard')) {
                $this->applyAuthGuardUsesProviderSemantics($statement, $parameters['rows']);
            }
        }

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

    /**
     * @return list<string>
     */
    public function usesConnectionsFor(string $jobKey): array
    {
        return $this->usesConnections[$jobKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function usesProvidersFor(string $guardKey): array
    {
        return $this->usesProviders[$guardKey] ?? [];
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function applyJobUsesConnectionSemantics(string $statement, array $rows): void
    {
        $replacesUsesConnection = str_contains($statement, '[old:USES_CONNECTION]')
            && str_contains($statement, 'DELETE old');

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                continue;
            }

            $jobKey = $row['key'];
            $connection = is_string($row['connection'] ?? null) ? $row['connection'] : '';

            if ($replacesUsesConnection) {
                unset($this->usesConnections[$jobKey]);
                if ($connection !== '') {
                    $this->usesConnections[$jobKey] = [$connection];
                }

                continue;
            }

            // Legacy append-only MERGE behaviour (no DELETE): keep stale edges.
            if ($connection === '') {
                continue;
            }

            $existing = $this->usesConnections[$jobKey] ?? [];
            if (! in_array($connection, $existing, true)) {
                $existing[] = $connection;
            }
            $this->usesConnections[$jobKey] = $existing;
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function applyAuthGuardUsesProviderSemantics(string $statement, array $rows): void
    {
        $replacesUsesProvider = str_contains($statement, '[old:USES_PROVIDER]')
            && str_contains($statement, 'DELETE old');

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                continue;
            }

            $guardKey = $row['key'];
            $provider = is_string($row['provider'] ?? null) ? $row['provider'] : '';

            if ($replacesUsesProvider) {
                unset($this->usesProviders[$guardKey]);
                if ($provider !== '') {
                    $this->usesProviders[$guardKey] = [$provider];
                }

                continue;
            }

            if ($provider === '') {
                continue;
            }

            $existing = $this->usesProviders[$guardKey] ?? [];
            if (! in_array($provider, $existing, true)) {
                $existing[] = $provider;
            }
            $this->usesProviders[$guardKey] = $existing;
        }
    }
}
