<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Closure;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Resolves typed constructor/method parameters to container identifiers.
 */
final class ParameterDependencyResolver
{
    /** @var list<string> */
    private const MIDDLEWARE_FRAMEWORK_TYPES = [
        Closure::class,
        'Illuminate\Http\Request',
        'Illuminate\Http\Response',
        'Symfony\Component\HttpFoundation\Request',
        'Symfony\Component\HttpFoundation\Response',
    ];

    /**
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    public function resolve(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return [null, 'Ignored', null];
        }

        if ($type instanceof ReflectionUnionType) {
            $resolved = $this->classNameFromUnionType($type);
            if ($resolved !== null) {
                return [$resolved, $this->kindForTypeName($resolved), null];
            }

            return [null, 'Ignored', null];
        }

        if (! $type instanceof ReflectionNamedType) {
            return [null, 'Ignored', null];
        }

        if ($type->isBuiltin()) {
            return [null, 'Ignored', null];
        }

        $name = $type->getName();
        if ($this->shouldSkipType($name)) {
            return [null, 'Ignored', null];
        }

        return [$name, $this->kindForTypeName($name), null];
    }

    public function shouldSkipType(string $typeName): bool
    {
        return $typeName === Closure::class;
    }

    public function isMiddlewareFrameworkType(string $typeName): bool
    {
        return in_array($typeName, self::MIDDLEWARE_FRAMEWORK_TYPES, true);
    }

    private function classNameFromUnionType(ReflectionUnionType $type): ?string
    {
        $candidate = null;

        foreach ($type->getTypes() as $namedType) {
            if (! $namedType instanceof ReflectionNamedType || $namedType->isBuiltin()) {
                continue;
            }

            if ($candidate !== null) {
                return null;
            }

            $candidate = $namedType->getName();
        }

        return $candidate;
    }

    private function kindForTypeName(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }
}
