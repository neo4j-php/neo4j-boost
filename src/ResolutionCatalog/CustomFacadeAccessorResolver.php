<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * Resolves app-defined facades via getFacadeAccessor() introspection.
 */
final class CustomFacadeAccessorResolver
{
    public function __construct(
        private FacadeAccessorParser $accessorParser,
        private ContainerBindingAbstractResolver $abstractResolver,
        private ContainerBindingLifetime $bindingLifetime,
    ) {}

    public function resolve(string $facadeClass): ?ResolutionCatalogEntry
    {
        $accessor = $this->accessorFor($facadeClass);
        if ($accessor === null) {
            return null;
        }

        [$accessorAbstract, $bindingKey] = $this->accessorParser->normalize($accessor);
        $containerAbstract = $bindingKey ?? $accessorAbstract;
        $abstract = $bindingKey !== null
            ? $this->abstractResolver->resolveForBindingKey($bindingKey)
            : $accessorAbstract;

        return new ResolutionCatalogEntry(
            identifier: $facadeClass,
            abstract: $abstract,
            bindsToType: $this->bindingLifetime->forAccessor($containerAbstract),
            source: ResolutionCatalogSource::CustomFacade,
            bindingKey: $bindingKey,
            facadeClass: $facadeClass,
        );
    }

    private function accessorFor(string $facadeClass): ?string
    {
        $accessor = $this->accessorParser->read($facadeClass);
        if ($accessor !== null) {
            return $accessor;
        }

        if (! class_exists($facadeClass) || ! is_subclass_of($facadeClass, Facade::class)) {
            return null;
        }

        Log::warning('Custom facade accessor must return a string.', [
            'facade' => $facadeClass,
        ]);

        return null;
    }
}
