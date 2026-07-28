<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;

final class ContainerBindingAbstractResolver
{
    public function __construct(
        private Application $app,
    ) {}

    public function resolveForBindingKey(string $bindingKey): string
    {
        if ($this->app->bound($bindingKey)) {
            $bindings = $this->app->getBindings();
            $concrete = $bindings[$bindingKey]['concrete'] ?? null;
            $resolved = $this->resolveConcreteName($bindingKey, $concrete);
            if ($resolved !== null) {
                return $resolved;
            }

            try {
                return $this->app->make($bindingKey)::class;
            } catch (Throwable) {
                // Fall through to the binding key.
            }
        }

        return $bindingKey;
    }

    private function resolveConcreteName(string $abstract, mixed $concrete): ?string
    {
        if (is_string($concrete)) {
            $name = trim($concrete);

            return $name === '' ? null : $name;
        }

        if ($concrete instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($concrete);
                $static = $reflection->getStaticVariables();
                if (isset($static['concrete']) && is_string($static['concrete'])) {
                    return $static['concrete'];
                }
                if (isset($static['abstract']) && is_string($static['abstract'])) {
                    return $static['abstract'];
                }

                $returnType = $reflection->getReturnType();
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    return $returnType->getName();
                }
            } catch (Throwable) {
                return null;
            }

            return null;
        }

        if (is_object($concrete)) {
            return $concrete::class;
        }

        return null;
    }
}
