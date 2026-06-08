<?php

namespace Neo4j\LaravelBoost\Support;

final class Neo4jMcpConfig
{
    public const STDIO_PASSWORD_REQUIRED_MESSAGE = 'Neo4j password is required for STDIO mode. Set a valid Neo4j password in your .env: NEO4J_PASSWORD=...';

    public static function transport(): string
    {
        $transport = config('neo4j-boost.neo4j_mcp.transport');

        if (! is_string($transport) || $transport === '') {
            $transport = config('neo4j-boost.transport.driver', 'stdio');
        }

        return strtolower((string) $transport);
    }

    public static function isStdioTransport(): bool
    {
        return self::transport() === 'stdio';
    }

    public static function neo4jPassword(): ?string
    {
        $password = config('neo4j-boost.transport.stdio.env.NEO4J_PASSWORD');

        if (! is_string($password)) {
            return null;
        }

        $trimmed = trim($password);

        return $trimmed === '' ? null : $trimmed;
    }

    public static function hasNeo4jPassword(): bool
    {
        return self::neo4jPassword() !== null;
    }

    public static function stdioPasswordRequiredMessage(): string
    {
        return self::STDIO_PASSWORD_REQUIRED_MESSAGE;
    }

    public static function stdioCommand(): string
    {
        $installer = new Neo4jMcpInstaller;

        if ($installer->isInstalled()) {
            return $installer->getBinaryPath();
        }

        $configured = config('neo4j-boost.transport.stdio.command', 'neo4j-mcp');

        return is_string($configured) && $configured !== '' ? $configured : 'neo4j-mcp';
    }

    /**
     * @return array<string, string>
     */
    public static function stdioEnvironment(): array
    {
        $fromGetenv = getenv();
        $base = is_array($fromGetenv) ? $fromGetenv : [];
        $configured = config('neo4j-boost.transport.stdio.env', []);
        $overrides = is_array($configured) ? $configured : [];

        return array_merge($base, array_filter(
            $overrides,
            static fn (mixed $value): bool => is_string($value) || is_numeric($value)
        ));
    }
}
