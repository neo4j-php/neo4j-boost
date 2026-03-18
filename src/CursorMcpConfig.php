<?php

namespace Neo4j\LaravelBoost;

class CursorMcpConfig
{
    public const SERVER_NAME = 'neo4j-boost';

    public const LARAVEL_BOOST_SERVER_NAME = 'laravel-boost';

    /**
     * When Laravel Boost is present, we expose one MCP server (laravel-boost) with all tools including Neo4j.
     * When Boost is not present, we expose the Neo4j MCP server (neo4j-boost) via HTTP URL.
     */
    public static function writeOrMerge(string $basePath): bool
    {
        $dir = $basePath . '/.cursor';
        $file = $dir . '/mcp.json';

        $serverToAdd = self::getServerConfigForEnvironment();
        $existing = [];
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data) && isset($data['mcpServers']) && is_array($data['mcpServers'])) {
                    $existing = $data['mcpServers'];
                }
            }
        }
        // One MCP server only: when adding laravel-boost, remove neo4j-boost so all tools (including Neo4j) are in one server.
        if (array_key_first($serverToAdd) === self::LARAVEL_BOOST_SERVER_NAME) {
            unset($existing[self::SERVER_NAME]);
        }
        $servers = array_merge($existing, $serverToAdd);
        $data = ['mcpServers' => $servers];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true)) {
                return false;
            }
        }

        return @file_put_contents($file, $json) !== false;
    }

    /**
     * One server only: laravel-boost when Boost is present (all tools), neo4j-boost when not.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function getServerConfigForEnvironment(): array
    {
        if (class_exists(\Laravel\Boost\Mcp\ToolRegistry::class)) {
            return [
                self::LARAVEL_BOOST_SERVER_NAME => [
                    'command' => 'php',
                    'args' => ['artisan', 'boost:mcp'],
                ],
            ];
        }

        return [
            self::SERVER_NAME => [
                'url' => config('neo4j-boost.http.url', 'http://localhost:8080/mcp'),
            ],
        ];
    }

    public static function getPath(string $basePath): string
    {
        return $basePath . '/.cursor/mcp.json';
    }
}
