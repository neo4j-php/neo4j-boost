<?php

namespace Neo4j\LaravelBoost\Boost\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Neo4j\LaravelBoost\Contracts\Neo4jMcpClientInterface;

#[IsReadOnly]
final class ListGdsProceduresTool extends Tool
{
    protected string $name = 'list-gds-procedures';

    protected string $description = 'Lists available Graph Data Science (GDS) procedures. Requires the GDS library to be installed on the Neo4j server.';

    public function __construct(
        private Neo4jMcpClientInterface $client
    ) {}

    public function handle(Request $request): Response
    {
        $result = $this->client->callTool('list-gds-procedures', []);

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
