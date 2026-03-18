<?php

namespace Neo4j\LaravelBoost;

class CursorMcpConfig
{
    public const SERVER_NAME = 'neo4j-boost';

    public static function getDefaultServerConfig(): array
    {
        $url = rtrim(config('neo4j-boost.http.url', 'http://localhost:8080/mcp'), '/');
        if (! str_ends_with($url, '/mcp')) {
            $url .= '/mcp';
        }

        return [
            'url' => $url,
        ];
    }

    public static function writeOrMerge(string $basePath): bool
    {
        $dir = $basePath . '/.cursor';
        $file = $dir . '/mcp.json';

        $servers = [self::SERVER_NAME => self::getDefaultServerConfig()];

        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $existing = $data['mcpServers'] ?? [];
                    if (is_array($existing)) {
                        $servers = array_merge($existing, $servers);
                    }
                }
            }
        }

        $data = ['mcpServers' => $servers];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true)) {
                return false;
            }
        }

        return @file_put_contents($file, $json) !== false;
    }

    public static function getPath(string $basePath): string
    {
        return $basePath . '/.cursor/mcp.json';
    }
}
