<?php

namespace Neo4j\LaravelBoost\Support;

final class EnvFileWriter
{
    /**
     * @return list<string>
     */
    public static function neo4jMcpUrlTemplateLines(): array
    {
        return [
            '',
            '# Neo4j MCP server (HTTP) — used by neo4j/laravel-boost',
            'NEO4J_MCP_URL=http://localhost:8080/mcp',
            '# NEO4J_MCP_USERNAME=neo4j',
            '# NEO4J_MCP_PASSWORD=',
        ];
    }

    public static function hasNeo4jMcpUrl(string $envPath): bool
    {
        if (! is_file($envPath)) {
            return false;
        }

        $content = @file_get_contents($envPath);
        if ($content === false) {
            return false;
        }

        foreach (preg_split('/\R/', $content) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! str_starts_with($trimmed, 'NEO4J_MCP_URL=')) {
                continue;
            }

            $value = trim(substr($trimmed, strlen('NEO4J_MCP_URL=')), " \t\"'");

            return $value !== '';
        }

        return false;
    }

    public static function appendNeo4jMcpUrlTemplate(string $envPath): bool
    {
        $lines = implode(PHP_EOL, self::neo4jMcpUrlTemplateLines()).PHP_EOL;

        return @file_put_contents($envPath, $lines, FILE_APPEND | LOCK_EX) !== false;
    }

    public static function ensureEnvFileExists(string $envPath, string $examplePath): bool
    {
        if (is_file($envPath)) {
            return true;
        }

        if (! is_file($examplePath)) {
            return false;
        }

        return @copy($examplePath, $envPath) !== false;
    }
}
