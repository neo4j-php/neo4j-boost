<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

/**
 * Discovers configured broadcast connections from the live Laravel broadcasting config.
 */
final class BroadcastConnectionExtractor
{
    /**
     * @return array<int, array{key: string, driver: string, is_default: bool}>
     */
    public function extract(?string $defaultConnection = null, ?array $connections = null): array
    {
        $defaultConnection ??= (string) config('broadcasting.default', 'null');
        $connections ??= (array) config('broadcasting.connections', []);

        $rows = [];

        foreach ($connections as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $driver = $config['driver'] ?? '';

            $rows[] = [
                'key' => $name,
                'driver' => is_string($driver) ? $driver : '',
                'is_default' => $name === $defaultConnection,
            ];
        }

        return $rows;
    }
}
