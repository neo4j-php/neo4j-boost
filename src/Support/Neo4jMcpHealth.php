<?php

namespace Neo4j\LaravelBoost\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

final class Neo4jMcpHealth
{
    public function isBinaryInstalled(): bool
    {
        $installer = new Neo4jMcpInstaller;

        return $installer->isInstalled();
    }

    public function isServerReachable(): bool
    {
        $url = $this->httpUrl();

        try {
            $request = Http::timeout(3)
                ->connectTimeout(2)
                ->acceptJson()
                ->asJson();

            $username = config('neo4j-boost.transport.http.username');
            $password = config('neo4j-boost.transport.http.password');
            if (is_string($username) && $username !== '' && is_string($password)) {
                $request = $request->withBasicAuth($username, $password);
            }

            $response = $request->post($url, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]);

            if ($response->successful()) {
                return true;
            }

            $isReachableStatus = in_array($response->status(), [400, 401, 403, 405, 406, 415], true);
            if ($isReachableStatus) {
                return true;
            }

            $this->emitUnreachableMessageForTinker($url);

            return false;
        } catch (Throwable) {
            $this->emitUnreachableMessageForTinker($url);

            return false;
        }
    }

    /**
     * @return array{
     *   status: 'healthy'|'degraded'|'unhealthy',
     *   binary_installed: bool,
     *   server_reachable: bool,
     *   http_url: string,
     *   binary_path: string,
     *   suggested_resolutions: list<string>
     * }
     */
    public function diagnose(): array
    {
        $binaryInstalled = $this->isBinaryInstalled();
        $serverReachable = $this->isServerReachable();

        $status = match (true) {
            $binaryInstalled && $serverReachable => 'healthy',
            $binaryInstalled || $serverReachable => 'degraded',
            default => 'unhealthy',
        };

        $suggestedResolutions = [];

        if (! $binaryInstalled) {
            $suggestedResolutions[] = 'Install the official binary: php artisan neo4j-boost:install-mcp';
        }

        if (! $serverReachable) {
            $suggestedResolutions[] = 'Start neo4j-mcp in HTTP mode and verify NEO4J_MCP_URL points to /mcp.';
            $suggestedResolutions[] = 'Verify Neo4j MCP auth settings (NEO4J_MCP_USERNAME / NEO4J_MCP_PASSWORD) if your endpoint requires authentication.';
        }

        if ($binaryInstalled && ! $serverReachable) {
            $installer = new Neo4jMcpInstaller;
            $suggestedResolutions[] = 'Try running local binary in HTTP mode: '.$installer->getBinaryPath().' --neo4j-transport-mode http';
        }

        if ($serverReachable && ! $binaryInstalled) {
            $suggestedResolutions[] = 'Binary installation is optional when using a remote MCP server URL.';
        }

        $installer = new Neo4jMcpInstaller;

        return [
            'status' => $status,
            'binary_installed' => $binaryInstalled,
            'server_reachable' => $serverReachable,
            'http_url' => $this->httpUrl(),
            'binary_path' => $installer->getBinaryPath(),
            'suggested_resolutions' => $suggestedResolutions,
        ];
    }

    private function httpUrl(): string
    {
        $httpUrl = config('neo4j-boost.http.url');
        if (is_string($httpUrl) && $httpUrl !== '') {
            return $httpUrl;
        }

        $transportHttpUrl = config('neo4j-boost.transport.http.url');
        if (is_string($transportHttpUrl) && $transportHttpUrl !== '') {
            return $transportHttpUrl;
        }

        return 'http://localhost:8080/mcp';
    }

    private function emitUnreachableMessageForTinker(string $url): void
    {
        if (! $this->runningInTinker()) {
            return;
        }

        fwrite(
            STDOUT,
            'Neo4j MCP server is not reachable at '.$url.'. Start it or run php artisan neo4j-boost:setup.'.PHP_EOL
        );
    }

    private function runningInTinker(): bool
    {
        if (! function_exists('app') || ! app()->runningInConsole()) {
            return false;
        }

        return ($_SERVER['argv'][1] ?? null) === 'tinker';
    }
}
