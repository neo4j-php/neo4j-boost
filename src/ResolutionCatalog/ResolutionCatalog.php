<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

final class ResolutionCatalog
{
    public function __construct(
        private LaravelFirstPartyFacadeCatalog $facades,
        private CustomFacadeAccessorResolver $customFacades,
        private RealTimeFacadeResolver $realTimeFacades,
    ) {}

    public function resolveFacade(string $facadeClass): ?ResolutionCatalogEntry
    {
        $firstParty = $this->facades->indexedByFacadeClass()[$facadeClass] ?? null;
        if ($firstParty !== null) {
            return $firstParty;
        }

        if ($this->realTimeFacades->isRealTimeFacade($facadeClass)) {
            return $this->realTimeFacades->resolve($facadeClass);
        }

        return $this->customFacades->resolve($facadeClass);
    }

    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function facadeEntries(): array
    {
        return $this->facades->entries();
    }

    /**
     * @return list<class-string>
     */
    public function firstPartyFacadeClasses(): array
    {
        return $this->facades->facadeClasses();
    }
}
