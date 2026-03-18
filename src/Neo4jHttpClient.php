<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Support\Facades\Http;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;

/**
 * HTTP client for the Neo4j MCP server.
 * Performs MCP handshake (initialize, notifications/initialized) then tools/call.
 */
class Neo4jHttpClient implements Neo4jMcpClientInterface
{
    private const int TIMEOUT = 60;
    private const int INIT_ID = 1;
    private const int TOOL_CALL_ID = 2;

    public function callTool(string $toolName, array $arguments = []): array
    {
        $url = config('neo4j-boost.http.url', 'http://localhost:8080/mcp');
        $username = config('neo4j-boost.http.username');
        $password = config('neo4j-boost.http.password');

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
        $initResponse = $client->post($url, $initPayload);
        if ($initResponse->failed()) {
            throw new \RuntimeException(
                'Neo4j MCP HTTP initialize failed (status ' . $initResponse->status() . '). ' . trim((string) $initResponse->body())
            );
        }
        $initBody = $initResponse->json();
        if (is_array($initBody) && isset($initBody['error'])) {
            $msg = is_array($initBody['error']) && isset($initBody['error']['message'])
                ? $initBody['error']['message']
                : (string) json_encode($initBody['error']);
            throw new \RuntimeException('Neo4j MCP HTTP initialize: ' . $msg);
        }

        $sessionId = $initResponse->header('Mcp-Session-Id') ?? $initResponse->header('mcp-session-id');
        if (is_array($sessionId)) {
            $sessionId = $sessionId[0] ?? null;
        }
        if ($sessionId !== null && $sessionId !== '') {
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
        $response = $client->post($url, $payload);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Neo4j MCP HTTP request failed (status ' . $response->status() . '). ' . trim((string) $response->body())
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('Neo4j MCP HTTP: invalid JSON response.');
        }

        if (isset($body['error'])) {
            $message = is_array($body['error']) && isset($body['error']['message'])
                ? $body['error']['message']
                : (string) json_encode($body['error']);
            throw new \RuntimeException('Neo4j MCP HTTP: ' . $message);
        }

        return $body['result'] ?? [];
    }
}
