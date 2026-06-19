<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;

/**
 * Builds Neo4j export rows for the facade resolution catalog.
 */
final class FacadeCatalogExporter
{
    public function __construct(
        private ResolutionCatalog $catalog,
    ) {}

    /**
     * @param  list<string>  $appClassNames
     * @return list<array{facade_class: string, abstract: string, abstractKind: string, binding_key: string, source: string, binds_to_type: string}>
     */
    public function rowsForAppClasses(array $appClassNames): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->catalog->facadeEntries() as $entry) {
            if ($entry->facadeClass === null) {
                continue;
            }

            $rows[] = $this->rowFromEntry($entry);
            $seen[$entry->facadeClass] = true;
        }

        foreach ($appClassNames as $className) {
            if (isset($seen[$className]) || ! $this->isCustomFacadeClass($className)) {
                continue;
            }

            $entry = $this->catalog->resolveFacade($className);
            if ($entry === null || $entry->facadeClass === null) {
                continue;
            }

            $rows[] = $this->rowFromEntry($entry);
            $seen[$entry->facadeClass] = true;
        }

        return $rows;
    }

    /**
     * @return array{facade_class: string, abstract: string, abstractKind: string, binding_key: string, source: string, binds_to_type: string}
     */
    private function rowFromEntry(ResolutionCatalogEntry $entry): array
    {
        return [
            'facade_class' => $entry->facadeClass ?? $entry->identifier,
            'abstract' => $entry->abstract,
            'abstractKind' => $this->kindForAbstract($entry->abstract),
            'binding_key' => $entry->bindingKey ?? '',
            'source' => $entry->source->value,
            'binds_to_type' => $entry->bindsToType->value,
        ];
    }

    private function kindForAbstract(string $abstract): string
    {
        if (interface_exists($abstract)) {
            return 'Interface';
        }

        if (class_exists($abstract)) {
            return 'Class';
        }

        return 'Alias';
    }

    private function isCustomFacadeClass(string $className): bool
    {
        return class_exists($className)
            && is_subclass_of($className, Facade::class)
            && ! str_starts_with($className, 'Illuminate\\Support\\Facades\\');
    }
}
