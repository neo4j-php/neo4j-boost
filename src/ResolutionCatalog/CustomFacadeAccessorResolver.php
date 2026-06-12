<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;
use ReflectionClass;

/**
 * Resolves app-defined facades via getFacadeAccessor() introspection.
 */
final class CustomFacadeAccessorResolver
{
    public function resolve(string $facadeClass): ?ResolutionCatalogEntry
    {
        if (! class_exists($facadeClass) || ! is_subclass_of($facadeClass, Facade::class)) {
            return null;
        }

        if (str_starts_with($facadeClass, 'Illuminate\\Support\\Facades\\')) {
            return null;
        }

        $accessor = $this->accessorFor($facadeClass);
        if ($accessor === null || $accessor === '') {
            return null;
        }

        [$abstract, $bindingKey] = $this->normalizeAccessor($accessor);

        return new ResolutionCatalogEntry(
            identifier: $facadeClass,
            kind: ResolutionCatalogKind::Facade,
            abstract: $abstract,
            bindsToType: LaravelBindingLifetime::forClassAccessor($accessor),
            source: ResolutionCatalogSource::CustomFacade,
            bindingKey: $bindingKey,
            facadeClass: $facadeClass,
        );
    }

    private function accessorFor(string $facadeClass): ?string
    {
        $reflection = new ReflectionClass($facadeClass);
        $method = $reflection->getMethod('getFacadeAccessor');
        $accessor = $method->invoke(null);

        if (is_string($accessor)) {
            return $accessor;
        }

        if (is_object($accessor)) {
            return $accessor::class;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: null|string}
     */
    private function normalizeAccessor(string $accessor): array
    {
        if (str_contains($accessor, '\\')) {
            return [$accessor, null];
        }

        return [$accessor, $accessor];
    }
}
