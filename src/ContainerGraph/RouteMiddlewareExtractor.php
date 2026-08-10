<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Discovers middleware attached to exportable controller routes and maps each
 * entry to a Middleware node identified by a container Identifier.
 *
 * Uses {@see Router::gatherRouteMiddleware()} so middleware groups and aliases
 * are expanded the same way Laravel dispatches the route.
 */
final class RouteMiddlewareExtractor
{
    public function __construct(
        private RouteHandlerExtractor $routeHandlerExtractor = new RouteHandlerExtractor,
    ) {}

    /**
     * @return array<int, array{route_key: string, middleware_key: string, identifier: string, identifier_kind: string, parameters: string, order: int}>
     */
    public function extract(?Router $router = null): array
    {
        $router ??= app('router');
        $exportableRouteKeys = [];
        foreach ($this->routeHandlerExtractor->extract($router) as $handler) {
            $exportableRouteKeys[$handler['key']] = true;
        }

        $rows = [];
        $seen = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $routeKey = $this->routeKey($route);
            if (! isset($exportableRouteKeys[$routeKey])) {
                continue;
            }

            $order = 0;
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                $parsed = $this->parseMiddleware($middleware);
                if ($parsed === null) {
                    continue;
                }

                $dedupe = $routeKey."\0".$parsed['middleware_key']."\0".$parsed['parameters']."\0".$order;
                if (isset($seen[$dedupe])) {
                    $order++;

                    continue;
                }
                $seen[$dedupe] = true;

                $rows[] = [
                    'route_key' => $routeKey,
                    'middleware_key' => $parsed['middleware_key'],
                    'identifier' => $parsed['identifier'],
                    'identifier_kind' => $parsed['identifier_kind'],
                    'parameters' => $parsed['parameters'],
                    'order' => $order,
                ];
                $order++;
            }
        }

        return $rows;
    }

    /**
     * @return null|array{middleware_key: string, identifier: string, identifier_kind: string, parameters: string}
     */
    private function parseMiddleware(mixed $middleware): ?array
    {
        if ($middleware instanceof Closure) {
            return null;
        }

        if (is_object($middleware)) {
            $class = $middleware::class;
            if ($class === Closure::class) {
                return null;
            }

            return [
                'middleware_key' => $class,
                'identifier' => $class,
                'identifier_kind' => $this->identifierKind($class),
                'parameters' => '',
            ];
        }

        if (! is_string($middleware) || $middleware === '') {
            return null;
        }

        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, null);
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return [
            'middleware_key' => $name,
            'identifier' => $name,
            'identifier_kind' => $this->identifierKind($name),
            'parameters' => is_string($parameters) ? $parameters : '',
        ];
    }

    private function routeKey(Route $route): string
    {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => ! in_array(strtoupper($method), ['HEAD'], true),
        ));
        sort($methods);

        $uri = '/'.ltrim($route->uri(), '/');

        return implode('|', $methods).' '.$uri;
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
