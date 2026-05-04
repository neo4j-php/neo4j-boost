<?php

namespace Neo4j\LaravelBoost\Contracts;

interface Neo4jMcpClientInterface
{
    /**
     * Call an MCP tool (e.g. get-schema, read-cypher, write-cypher).
     *
     * @param  array<string, mixed>  $arguments  Tool arguments.
     * @return array<string, mixed> MCP result (e.g. ['content' => [...], 'isError' => false])
     *
     * @throws \RuntimeException When the call fails.
     */
    public function callTool(string $toolName, array $arguments = []): array;
}
