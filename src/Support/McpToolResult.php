<?php

namespace Neo4j\LaravelBoost\Support;

final class McpToolResult
{
    /**
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    public static function text(string $text, bool $isError = false): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'isError' => $isError,
        ];
    }

    /**
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    public static function error(string $message): array
    {
        return self::text($message, isError: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    public static function jsonRows(array $rows): array
    {
        $encoded = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return self::error('Failed to encode query results as JSON.');
        }

        return self::text($encoded, isError: false);
    }
}
