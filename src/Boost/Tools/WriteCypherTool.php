<?php

namespace Neo4j\LaravelBoost\Boost\Tools;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;

final class WriteCypherTool extends Tool
{
    protected string $name = 'write-cypher';

    protected string $description = 'Executes a Cypher write query (CREATE, SET, DELETE, etc.) against the Neo4j database.';

    public function __construct(
        private Neo4jMcpClientInterface $client
    ) {}

    public function handle(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:1',
            'params' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $params = $validated['params'] ?? [];

        $arguments = [
            'query' => $validated['query'],
            'params' => $params === [] ? new \stdClass : $params,
        ];

        $result = $this->client->callTool('write-cypher', $arguments);

        if (! empty($result['isError'])) {
            $msg = $this->extractErrorText($result['content'] ?? []);

            return Response::error($msg ?: 'Neo4j MCP tool error');
        }

        $content = $result['content'] ?? $result;

        return Response::json(is_array($content) ? $content : ['result' => $content]);
    }

    /** @param array<int, mixed> $content */
    private function extractErrorText(array $content): string
    {
        $first = $content[0] ?? [];
        if (is_array($first) && isset($first['text'])) {
            return (string) $first['text'];
        }

        return '';
    }
}
