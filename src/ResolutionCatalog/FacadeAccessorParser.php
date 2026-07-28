<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;
use ReflectionClass;

final class FacadeAccessorParser
{
    public function read(string $facadeClass): ?string
    {
        if (! class_exists($facadeClass) || ! is_subclass_of($facadeClass, Facade::class)) {
            return null;
        }

        $reflection = new ReflectionClass($facadeClass);
        $accessor = $reflection->getMethod('getFacadeAccessor')->invoke(null);

        return is_string($accessor) && $accessor !== '' ? $accessor : null;
    }

    /**
     * @return array{0: string, 1: null|string}
     */
    public function normalize(string $accessor): array
    {
        if (str_contains($accessor, '\\')) {
            return [$accessor, null];
        }

        return [$accessor, $accessor];
    }
}
