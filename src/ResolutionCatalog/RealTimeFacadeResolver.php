<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

/**
 * Resolves Laravel real-time facades (Facades\<FQCN>::method) to the underlying
 * class the container resolves. Real-time facades are virtual: the leading
 * `Facades\` segment is stripped to recover the concrete class (SOFT-56).
 */
final class RealTimeFacadeResolver
{
    private const REALTIME_PREFIX = 'Facades\\';

    public function __construct(
        private ContainerBindingLifetime $bindingLifetime,
    ) {}

    public function resolve(string $facadeClass): ?ResolutionCatalogEntry
    {
        $underlying = $this->underlyingClass($facadeClass);
        if ($underlying === null) {
            return null;
        }

        return new ResolutionCatalogEntry(
            identifier: $facadeClass,
            abstract: $underlying,
            bindsToType: $this->bindingLifetime->forAccessor($underlying),
            source: ResolutionCatalogSource::AutoDiscoveredFacade,
            bindingKey: null,
            facadeClass: $facadeClass,
        );
    }

    public function isRealTimeFacade(string $facadeClass): bool
    {
        return $this->underlyingClass($facadeClass) !== null;
    }

    private function underlyingClass(string $facadeClass): ?string
    {
        $normalized = ltrim($facadeClass, '\\');
        if (! str_starts_with($normalized, self::REALTIME_PREFIX)) {
            return null;
        }

        $underlying = substr($normalized, strlen(self::REALTIME_PREFIX));

        return $underlying !== '' ? $underlying : null;
    }
}
