<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Str;
use ReflectionProperty;
use Throwable;

/**
 * Discovers Laravel authorization wiring from the live Gate:
 * model→policy registrations and defined Gate abilities.
 */
final class AuthorizationExtractor
{
    /**
     * @return array{
     *     policies: array<int, array{key: string, name: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string}>,
     *     abilities: array<int, array{key: string, name: string, handler_kind: string, identifier: string, identifier_kind: string, action: string}>
     * }
     */
    public function extract(?GateContract $gate = null): array
    {
        $gate ??= app(GateContract::class);

        return [
            'policies' => $this->policies($gate),
            'abilities' => $this->abilities($gate),
        ];
    }

    /**
     * @return array<int, array{key: string, name: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string}>
     */
    private function policies(GateContract $gate): array
    {
        if (! $gate instanceof Gate) {
            return [];
        }

        $rows = [];

        foreach ($gate->policies() as $model => $policy) {
            if (! is_string($model) || $model === '' || ! is_string($policy) || $policy === '') {
                continue;
            }

            $model = ltrim($model, '\\');
            $policy = ltrim($policy, '\\');

            $rows[] = [
                'key' => $model,
                'name' => class_basename($model),
                'model' => $model,
                'model_kind' => $this->kindForTypeName($model),
                'identifier' => $policy,
                'identifier_kind' => $this->kindForTypeName($policy),
                'action' => $policy,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{key: string, name: string, handler_kind: string, identifier: string, identifier_kind: string, action: string}>
     */
    private function abilities(GateContract $gate): array
    {
        if (! $gate instanceof Gate) {
            return [];
        }

        $stringCallbacks = $this->stringCallbacks($gate);
        $rows = [];

        foreach ($gate->abilities() as $ability => $callback) {
            if (! is_string($ability) || $ability === '') {
                continue;
            }

            $stringCallback = is_string($stringCallbacks[$ability] ?? null)
                ? $stringCallbacks[$ability]
                : null;

            if (is_string($stringCallback) && $stringCallback !== '') {
                [$class, $method] = Str::parseCallback($stringCallback, '__invoke');
                $class = ltrim((string) $class, '\\');
                $method = is_string($method) && $method !== '' ? $method : '__invoke';

                $rows[] = [
                    'key' => $ability,
                    'name' => $ability,
                    'handler_kind' => 'class',
                    'identifier' => $class,
                    'identifier_kind' => $this->kindForTypeName($class),
                    'action' => $class.'@'.$method,
                ];

                continue;
            }

            $handlerKind = $callback instanceof Closure ? 'closure' : 'unknown';

            $rows[] = [
                'key' => $ability,
                'name' => $ability,
                'handler_kind' => $handlerKind,
                'identifier' => '',
                'identifier_kind' => '',
                'action' => '',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function stringCallbacks(Gate $gate): array
    {
        try {
            $property = new ReflectionProperty(Gate::class, 'stringCallbacks');
            /** @var mixed $value */
            $value = $property->getValue($gate);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        $callbacks = [];
        foreach ($value as $ability => $callback) {
            if (is_string($ability) && is_string($callback) && $callback !== '') {
                $callbacks[$ability] = $callback;
            }
        }

        return $callbacks;
    }

    private function kindForTypeName(string $typeName): string
    {
        if (interface_exists($typeName)) {
            return 'Interface';
        }

        if (class_exists($typeName)) {
            return 'Class';
        }

        return 'AbstractType';
    }
}
