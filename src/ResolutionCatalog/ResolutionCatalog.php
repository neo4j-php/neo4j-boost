<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

final class ResolutionCatalog
{
    public function __construct(
        private LaravelFirstPartyFacadeCatalog $facades,
        private GlobalHelperCatalog $helpers,
        private CustomFacadeAccessorResolver $customFacades,
    ) {}

    public function resolveFacade(string $facadeClass): ?ResolutionCatalogEntry
    {
        $firstParty = $this->facades->indexedByFacadeClass()[$facadeClass] ?? null;
        if ($firstParty !== null) {
            return $firstParty;
        }

        return $this->customFacades->resolve($facadeClass);
    }

    public function resolveHelper(string $helperName): ?ResolutionCatalogEntry
    {
        return $this->helpers->indexedByHelperName()[$helperName] ?? null;
    }

    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function facadeEntries(): array
    {
        return $this->facades->entries();
    }

    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function helperEntries(): array
    {
        return $this->helpers->entries();
    }

    /**
     * @return list<class-string>
     */
    public function firstPartyFacadeClasses(): array
    {
        return $this->facades->facadeClasses();
    }
}
