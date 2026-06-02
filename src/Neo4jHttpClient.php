<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Response;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;
use Neo4j\LaravelBoost\Support\Neo4jMcpHealth;
use Throwable;

/**
 * HTTP client for the Neo4j MCP server.
 * Performs MCP handshake (initialize, notifications/initialized) then tools/call.
 */
class Neo4jHttpClient implements Neo4jMcpClientInterface
{
    private const TIMEOUT = 60;

    private const INIT_ID = 1;

    private const TOOL_CALL_ID = 2;

    public function callTool(string $toolName, array $arguments = []): array
    {
        $url = $this->httpUrl();
        $username = config('neo4j-boost.http.username');
        $password = config('neo4j-boost.http.password');
        $health = new Neo4jMcpHealth;

        $client = Http::timeout(self::TIMEOUT)
            ->withHeaders(['Accept' => 'application/json, text/event-stream'])
            ->asJson();

        if ($username !== null && $password !== null) {
            $client = $client->withBasicAuth($username, $password);
        }

        // 1. Initialize (required before tool operations)
        $initPayload = [
            'jsonrpc' => '2.0',
            'id' => self::INIT_ID,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'neo4j-laravel-boost',
                    'version' => '1.0',
                ],
            ],
        ];
        try {
            $initResponse = $client->post($url, $initPayload);
        } catch (ConnectionException $exception) {
            return $this->reachabilityErrorResult($url, $health);
        } catch (Throwable $exception) {
            return $this->reachabilityErrorResult($url, $health);
        }

        if (in_array($initResponse->status(), [401, 403], true)) {
            return $this->errorResult(
                'Neo4j MCP authentication failed. Check NEO4J_MCP_USERNAME / NEO4J_MCP_PASSWORD.'
            );
        }

        if ($initResponse->failed()) {
            return $this->reachabilityErrorResult($url, $health);
        }
        $initBody = $initResponse->json();
        if (is_array($initBody) && isset($initBody['error'])) {
            $msg = is_array($initBody['error']) && isset($initBody['error']['message'])
                ? $initBody['error']['message']
                : (string) json_encode($initBody['error']);

            return $this->errorResult('Neo4j MCP HTTP initialize: '.$msg);
        }

        $sessionId = self::normalizeSessionHeader($initResponse->header('Mcp-Session-Id'))
            ?? self::normalizeSessionHeader($initResponse->header('mcp-session-id'));
        if ($sessionId !== null) {
            $client = $client->withHeaders(['Mcp-Session-Id' => $sessionId]);
        }

        // 2. Initialized notification (enters operation phase)
        $client->post($url, [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        // 3. tools/call
        $payload = [
            'jsonrpc' => '2.0',
            'id' => self::TOOL_CALL_ID,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments === [] ? new \stdClass : $arguments,
            ],
        ];
        try {
            $response = $client->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->reachabilityErrorResult($url, $health);
        } catch (Throwable $exception) {
            return $this->reachabilityErrorResult($url, $health);
        }

        if (in_array($response->status(), [401, 403], true)) {
            return $this->errorResult(
                'Neo4j MCP authentication failed. Check NEO4J_MCP_USERNAME / NEO4J_MCP_PASSWORD.'
            );
        }

        if ($response->failed()) {
            return $this->reachabilityErrorResult($url, $health);
        }

        $body = $response->json();
        if (! is_array($body)) {
            return $this->errorResult('Neo4j MCP HTTP: invalid JSON response.');
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) && isset($body['error']['message'])
                ? $body['error']['message']
                : (string) json_encode($body['error']);

            return $this->errorResult('Neo4j MCP HTTP: '.$message);
        }

        return $body['result'] ?? [];
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

    /**
     * @return array<string, mixed>
     */
    private function errorResult(string $message): array
    {
        $response = Response::error($message);
        $content = $response->content();

        return [
            'content' => [[
                'type' => 'text',
                'text' => (string) $content,
            ]],
            'isError' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reachabilityErrorResult(string $url, Neo4jMcpHealth $health): array
    {
        if (! $health->isBinaryInstalled()) {
            return $this->errorResult(
                'Neo4j MCP binary not found. Run php artisan neo4j-boost:install-mcp or neo4j-boost:setup.'
            );
        }

        return $this->errorResult(
            'Neo4j MCP server is not reachable at '.$url.'. Start it or run php artisan neo4j-boost:setup.'
        );
    }

    /**
     * @param  array<int, string>|string|null  $value
     */
    private static function normalizeSessionHeader(array|string|null $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_array($value)) {
            $first = $value[0] ?? '';

            return $first !== '' ? $first : null;
        }

        return null;
    }
}
