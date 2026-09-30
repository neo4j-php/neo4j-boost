<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Illuminate\Support\Collection;
use ReflectionObject;

/**
 * Discovers broadcast channel authenticators from the live default broadcaster
 * and maps class-based channel callbacks to container Abstracts for HANDLED_BY.
 *
 * Closure channel callbacks are exported as BroadcastChannel nodes without
 * HANDLED_BY (same stance as closure scheduled tasks).
 */
final class BroadcastChannelExtractor
{
    /**
     * @param  null|array<string, mixed>  $channels
     * @param  null|array<string, mixed>  $channelOptions
     * @return array<int, array{
     *     key: string,
     *     name: string,
     *     guards: string,
     *     action: string,
     *     identifier: string,
     *     identifier_kind: string
     * }>
     */
    public function extract(?array $channels = null, ?array $channelOptions = null): array
    {
        if ($channels === null) {
            $broadcaster = $this->defaultBroadcaster();
            $channels = $this->channelsFromBroadcaster($broadcaster);
            $channelOptions ??= $this->optionsFromBroadcaster($broadcaster);
        }

        $channelOptions ??= [];
        $rows = [];
        $seen = [];

        foreach ($channels as $pattern => $callback) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (isset($seen[$pattern])) {
                continue;
            }
            $seen[$pattern] = true;

            $resolved = $this->resolveCallback($callback);
            $guards = $this->guardsForChannel($pattern, $channelOptions);

            $rows[] = [
                'key' => $pattern,
                'name' => $this->channelDisplayName($pattern),
                'guards' => $guards,
                'action' => $resolved['action'] ?? '',
                'identifier' => $resolved['identifier'] ?? '',
                'identifier_kind' => $resolved['identifier_kind'] ?? '',
            ];
        }

        return $rows;
    }

    private function defaultBroadcaster(): ?object
    {
        if (! app()->bound(BroadcastingFactory::class)) {
            return null;
        }

        $factory = app(BroadcastingFactory::class);
        if (! is_object($factory) || ! method_exists($factory, 'connection')) {
            return null;
        }

        $broadcaster = $factory->connection();

        return is_object($broadcaster) ? $broadcaster : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function channelsFromBroadcaster(?object $broadcaster): array
    {
        if ($broadcaster === null || ! method_exists($broadcaster, 'getChannels')) {
            return [];
        }

        $channels = $broadcaster->getChannels();

        if ($channels instanceof Collection) {
            return $channels->all();
        }

        return is_array($channels) ? $channels : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFromBroadcaster(?object $broadcaster): array
    {
        if ($broadcaster === null) {
            return [];
        }

        $reflection = new ReflectionObject($broadcaster);
        if (! $reflection->hasProperty('channelOptions')) {
            return [];
        }

        $property = $reflection->getProperty('channelOptions');
        $options = $property->getValue($broadcaster);

        return is_array($options) ? $options : [];
    }

    /**
     * @return null|array{identifier: string, identifier_kind: string, action: string}
     */
    private function resolveCallback(mixed $callback): ?array
    {
        if ($callback instanceof Closure) {
            return null;
        }

        if (is_string($callback) && $callback !== '') {
            return [
                'identifier' => $callback,
                'identifier_kind' => $this->identifierKind($callback),
                'action' => $callback.'@join',
            ];
        }

        if (is_array($callback) && count($callback) === 2) {
            [$class, $method] = $callback;
            if (! is_string($class) || $class === '' || ! is_string($method) || $method === '') {
                return null;
            }

            return [
                'identifier' => $class,
                'identifier_kind' => $this->identifierKind($class),
                'action' => $class.'@'.$method,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $optionsByChannel
     */
    private function guardsForChannel(string $pattern, array $optionsByChannel): string
    {
        $options = $optionsByChannel[$pattern] ?? [];
        if (! is_array($options)) {
            return '';
        }

        $guards = $options['guards'] ?? null;
        if (is_string($guards) && $guards !== '') {
            return $guards;
        }

        if (is_array($guards)) {
            $names = [];
            foreach ($guards as $guard) {
                if (is_string($guard) && $guard !== '') {
                    $names[] = $guard;
                }
            }

            return implode(',', $names);
        }

        return '';
    }

    private function channelDisplayName(string $pattern): string
    {
        if (str_contains($pattern, '\\')) {
            $parts = explode('\\', $pattern);

            return (string) end($parts);
        }

        return $pattern;
    }

    private function identifierKind(string $identifier): string
    {
        if (interface_exists($identifier)) {
            return 'Interface';
        }

        if (class_exists($identifier)) {
            return 'Class';
        }

        return 'AbstractType';
    }
}
