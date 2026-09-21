<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support\Stubs;

use Laudis\Neo4j\Databags\SummarizedResult;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;

/**
 * Records replaceable relationship edges by applying Cypher semantics from the
 * statement text (Job USES_CONNECTION, Mailable USES_MAILER, AuthGuard USES_PROVIDER, Event HANDLED_BY).
 */
final class TrackingContainerGraphConnection extends ContainerGraphConnection
{
    /** @var array<string, list<string>> job key => connected queue connection keys */
    private array $usesConnections = [];

    /** @var array<string, list<string>> mailable key => mailer keys */
    private array $usesMailers = [];

    /** @var array<string, list<string>> mailable key => queue connection keys */
    private array $mailableUsesConnections = [];

    /** @var array<string, list<string>> auth guard key => provider keys */
    private array $usesProviders = [];

    /** @var array<string, list<string>> event key => listener abstract names */
    private array $handledBy = [];

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

            if (str_contains($statement, ':Mailable')) {
                $this->applyMailableEdgeSemantics($statement, $parameters['rows']);
            }

            if (str_contains($statement, ':AuthGuard')) {
                $this->applyAuthGuardUsesProviderSemantics($statement, $parameters['rows']);
            }

            if (str_contains($statement, ':Event')) {
                $this->applyEventHandledBySemantics($statement, $parameters['rows']);
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
    public function usesMailersFor(string $mailableKey): array
    {
        return $this->usesMailers[$mailableKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function mailableUsesConnectionsFor(string $mailableKey): array
    {
        return $this->mailableUsesConnections[$mailableKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function usesProvidersFor(string $guardKey): array
    {
        return $this->usesProviders[$guardKey] ?? [];
    }

    /**
     * @return list<string>
     */
    public function handledByFor(string $eventKey): array
    {
        $listeners = $this->handledBy[$eventKey] ?? [];
        sort($listeners);

        return $listeners;
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
    private function applyMailableEdgeSemantics(string $statement, array $rows): void
    {
        $replacesUsesMailer = str_contains($statement, '[oldMailer:USES_MAILER]')
            && str_contains($statement, 'DELETE oldMailer');
        $replacesUsesConnection = str_contains($statement, '[oldConn:USES_CONNECTION]')
            && str_contains($statement, 'DELETE oldConn');

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                continue;
            }

            $mailableKey = $row['key'];
            $mailer = is_string($row['mailer'] ?? null) ? $row['mailer'] : '';
            $connection = is_string($row['connection'] ?? null) ? $row['connection'] : '';

            if ($replacesUsesMailer) {
                unset($this->usesMailers[$mailableKey]);
                if ($mailer !== '') {
                    $this->usesMailers[$mailableKey] = [$mailer];
                }
            }

            if ($replacesUsesConnection) {
                unset($this->mailableUsesConnections[$mailableKey]);
                if ($connection !== '') {
                    $this->mailableUsesConnections[$mailableKey] = [$connection];
                }
            }
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

    /**
     * @param  array<int, mixed>  $rows
     */
    private function applyEventHandledBySemantics(string $statement, array $rows): void
    {
        $clearsHandledBy = str_contains($statement, '[old:HANDLED_BY]')
            && str_contains($statement, 'DELETE old');

        if ($clearsHandledBy) {
            foreach ($rows as $row) {
                if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                    continue;
                }

                unset($this->handledBy[$row['key']]);
            }

            return;
        }

        if (! str_contains($statement, 'HANDLED_BY')) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['key']) || ! is_string($row['key'])) {
                continue;
            }

            $identifier = is_string($row['identifier'] ?? null) ? $row['identifier'] : '';
            if ($identifier === '') {
                continue;
            }

            $existing = $this->handledBy[$row['key']] ?? [];
            if (! in_array($identifier, $existing, true)) {
                $existing[] = $identifier;
            }
            $this->handledBy[$row['key']] = $existing;
        }
    }
}
