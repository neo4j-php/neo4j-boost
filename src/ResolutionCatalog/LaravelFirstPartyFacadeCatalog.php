<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;
use ReflectionClass;

/**
 * First-party Laravel facade → container resolution mappings via accessor introspection.
 */
final class LaravelFirstPartyFacadeCatalog
{
    /** @var null|list<class-string<Facade>> */
    private ?array $facadeClasses = null;

    /** @var null|list<ResolutionCatalogEntry> */
    private ?array $entries = null;

    public function __construct(
        private FacadeAccessorParser $accessorParser,
        private ContainerBindingAbstractResolver $abstractResolver,
        private ContainerBindingLifetime $bindingLifetime,
    ) {}

    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];

        foreach ($this->facadeClasses() as $facadeClass) {
            $entry = $this->resolveFacade($facadeClass);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $this->entries = $entries;
    }

    /**
     * @return array<string, ResolutionCatalogEntry>
     */
    public function indexedByFacadeClass(): array
    {
        $indexed = [];

        foreach ($this->entries() as $entry) {
            if ($entry->facadeClass !== null) {
                $indexed[$entry->facadeClass] = $entry;
            }
        }

        return $indexed;
    }

    /**
     * @return list<class-string<Facade>>
     */
    public function facadeClasses(): array
    {
        if ($this->facadeClasses !== null) {
            return $this->facadeClasses;
        }

        $facadeDir = dirname((new ReflectionClass(Facade::class))->getFileName());
        $classes = [];

        foreach (glob($facadeDir.'/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if ($short === 'Facade') {
                continue;
            }

            $classes[] = 'Illuminate\\Support\\Facades\\'.$short;
        }

        sort($classes);

        return $this->facadeClasses = $classes;
    }

    private function resolveFacade(string $facadeClass): ?ResolutionCatalogEntry
    {
        $accessor = $this->accessorParser->read($facadeClass);
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
            source: ResolutionCatalogSource::LaravelFacade,
            bindingKey: $bindingKey,
            facadeClass: $facadeClass,
        );
    }
}
