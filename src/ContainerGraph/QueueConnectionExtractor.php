<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

/**
 * Discovers configured queue connections from the live Laravel queue config.
 */
final class QueueConnectionExtractor
{
    /**
     * @return array<int, array{key: string, driver: string, default_queue: string, is_default: bool}>
     */
    public function extract(?string $defaultConnection = null, ?array $connections = null): array
    {
        $defaultConnection ??= (string) config('queue.default', 'sync');
        $connections ??= (array) config('queue.connections', []);

        $rows = [];

        foreach ($connections as $name => $config) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $config = is_array($config) ? $config : [];
            $driver = $config['driver'] ?? '';
            $defaultQueue = $config['queue'] ?? '';

            $rows[] = [
                'key' => $name,
                'driver' => is_string($driver) ? $driver : '',
                'default_queue' => is_string($defaultQueue) ? $defaultQueue : '',
                'is_default' => $name === $defaultConnection,
            ];
        }

        return $rows;
    }
}
