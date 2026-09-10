<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Events\QueuedClosure;
use Illuminate\Support\Str;

/**
 * Discovers application event listeners from the live dispatcher and maps each
 * class-based listener to a container Abstract for HANDLED_BY edges.
 *
 * Closures and wildcard listeners are skipped (same stance as closure routes).
 */
final class EventListenerExtractor
{
    /**
     * @return array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>
     */
    public function extract(?object $events = null): array
    {
        $events ??= app('events');

        if (! is_object($events) || ! method_exists($events, 'getRawListeners')) {
            return [];
        }

        /** @var array<string, mixed> $rawListeners */
        $rawListeners = $events->getRawListeners();
        $rows = [];
        $seen = [];

        foreach ($rawListeners as $eventName => $listeners) {
            if (! is_string($eventName) || $eventName === '') {
                continue;
            }

            foreach ((array) $listeners as $listener) {
                $resolved = $this->resolveListener($listener);
                if ($resolved === null) {
                    continue;
                }

                [$identifier, $method, $action] = $resolved;
                $dedupeKey = $eventName."\0".$identifier."\0".$method;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $rows[] = [
                    'key' => $eventName,
                    'name' => $this->eventDisplayName($eventName),
                    'action' => $action,
                    'identifier' => $identifier,
                    'identifier_kind' => $this->identifierKind($identifier),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return null|array{0: string, 1: string, 2: string}
     */
    private function resolveListener(mixed $listener): ?array
    {
        if ($listener instanceof Closure || $listener instanceof QueuedClosure) {
            return null;
        }

        if (is_array($listener)) {
            if (count($listener) !== 2) {
                return null;
            }

            [$class, $method] = $listener;
            if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
                return null;
            }

            return [$class, $method, $class.'@'.$method];
        }

        if (! is_string($listener) || $listener === '') {
            return null;
        }

        [$class, $method] = Str::parseCallback($listener, 'handle');
        if (! is_string($class) || $class === '') {
            return null;
        }

        $method = is_string($method) && $method !== '' ? $method : 'handle';

        return [$class, $method, $class.'@'.$method];
    }

    private function eventDisplayName(string $eventName): string
    {
        if (str_contains($eventName, '\\')) {
            $parts = explode('\\', $eventName);

            return (string) end($parts);
        }

        return $eventName;
    }

    private function identifierKind(string $identifier): string
    {
        if (interface_exists($identifier)) {
            return 'Interface';
        }

        if (class_exists($identifier)) {
            return 'Class';
        }

        return 'Alias';
    }
}
