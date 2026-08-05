<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionFunction;
use Throwable;

/**
 * Discovers application routes and maps each controller/invokable action
 * to a container Identifier for HANDLED_BY edges.
 */
final class RouteHandlerExtractor
{
    /**
     * @return array<int, array{key: string, uri: string, methods: string, name: string, action: string, identifier: string, identifier_kind: string}>
     */
    public function extract(?Router $router = null): array
    {
        $router ??= app('router');
        $rows = [];
        $seen = [];

        foreach ($router->getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $identifier = $this->resolveIdentifier($route);
            if ($identifier === null) {
                continue;
            }

            $methods = $this->normalizedMethods($route);
            $uri = '/'.ltrim($route->uri(), '/');
            $key = $methods.' '.$uri;

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'key' => $key,
                'uri' => $uri,
                'methods' => $methods,
                'name' => (string) ($route->getName() ?? ''),
                'action' => $this->actionLabel($route),
                'identifier' => $identifier,
                'identifier_kind' => $this->identifierKind($identifier),
            ];
        }

        return $rows;
    }

    private function resolveIdentifier(Route $route): ?string
    {
        $action = $route->getAction();

        if (isset($action['controller']) && is_string($action['controller']) && $action['controller'] !== '') {
            $controller = $action['controller'];
            if (str_contains($controller, '@')) {
                return explode('@', $controller, 2)[0];
            }

            // Invokable controller class string.
            if (class_exists($controller)) {
                return $controller;
            }

            return explode('@', $controller, 2)[0];
        }

        if (isset($action['uses']) && is_string($action['uses']) && str_contains($action['uses'], '@')) {
            return explode('@', $action['uses'], 2)[0];
        }

        $actionMethod = $route->getActionMethod();
        if (is_string($actionMethod)
            && $actionMethod !== ''
            && $actionMethod !== 'Closure'
            && class_exists($actionMethod)
        ) {
            return $actionMethod;
        }

        // Closures / non-class actions are skipped for Identifier resolution.
        return null;
    }

    private function actionLabel(Route $route): string
    {
        $action = $route->getAction();

        if (isset($action['controller']) && is_string($action['controller'])) {
            return $action['controller'];
        }

        if (isset($action['uses']) && is_string($action['uses'])) {
            return $action['uses'];
        }

        try {
            $uses = $route->getAction('uses');
            if (is_string($uses)) {
                return $uses;
            }
            if ($uses instanceof \Closure) {
                $reflection = new ReflectionFunction($uses);

                return 'Closure@'.$reflection->getFileName().':'.$reflection->getStartLine();
            }
        } catch (Throwable) {
            // Fall through.
        }

        return 'Closure';
    }

    private function normalizedMethods(Route $route): string
    {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => ! in_array(strtoupper($method), ['HEAD'], true),
        ));

        sort($methods);

        return implode('|', $methods);
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
