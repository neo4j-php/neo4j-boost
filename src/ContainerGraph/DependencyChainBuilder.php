<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalogEntry;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;

/**
 * Builds Instance → Dependency → Identifier export rows (SOFT-58).
 */
final class DependencyChainBuilder
{
    public function __construct(
        private BindingLifetimeResolver $lifetimeResolver,
    ) {}

    /**
     * @param  array<string, array{concrete: mixed, shared: bool}>  $bindings
     * @param  array{class: string, dependency: string, dependencyKind: string, type: string, source?: string, via?: string, file?: string, line?: int}  $row
     * @return array{
     *     instance: string,
     *     dependency_key: string,
     *     access: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     lifetime: string,
     *     via: string,
     *     file: string,
     *     line: int
     * }
     */
    public function fromLegacyDependencyRow(array $row, array $bindings): array
    {
        $access = DependencyAccessType::fromDependsOnType((string) $row['type']);
        $identifier = (string) $row['dependency'];
        $identifierKind = (string) ($row['dependencyKind'] ?? $this->kindForIdentifier($identifier));

        return $this->chain(
            instance: (string) $row['class'],
            access: $access,
            identifier: $identifier,
            identifierKind: $identifierKind,
            lifetime: $this->lifetimeResolver->forIdentifier($identifier, $bindings),
            via: (string) ($row['via'] ?? ''),
            file: (string) ($row['file'] ?? ''),
            line: (int) ($row['line'] ?? 0),
        );
    }

    /**
     * @return array{
     *     instance: string,
     *     dependency_key: string,
     *     access: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     lifetime: string,
     *     via: string,
     *     file: string,
     *     line: int
     * }
     */
    public function fromUnresolvedRow(array $row, array $bindings): array
    {
        $identifier = (string) $row['name'];

        $chain = $this->chain(
            instance: (string) $row['class'],
            access: DependencyAccessType::fromDependsOnType((string) $row['type']),
            identifier: $identifier,
            identifierKind: 'Unresolved',
            lifetime: $this->lifetimeResolver->forIdentifier($identifier, $bindings),
            via: 'unresolved',
            file: '',
            line: 0,
        );
        $chain['reason'] = (string) ($row['reason'] ?? 'unresolved');

        return $chain;
    }

    /**
     * Catalog-only facade resolution path (no Instance).
     *
     * @return array{
     *     instance: string,
     *     dependency_key: string,
     *     access: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     lifetime: string,
     *     via: string,
     *     file: string,
     *     line: int
     * }
     */
    /**
     * @param  array{facade_class: string, abstract: string, abstractKind: string, binding_key: string, source: string, binds_to_type: string}  $row
     * @return array{
     *     instance: string,
     *     dependency_key: string,
     *     access: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     lifetime: string,
     *     via: string,
     *     file: string,
     *     line: int,
     *     reason?: string
     * }
     */
    public function fromFacadeExportRow(array $row): array
    {
        $identifier = $row['binding_key'] !== '' ? $row['binding_key'] : $row['abstract'];

        return $this->chain(
            instance: '',
            access: DependencyAccessType::Facade,
            identifier: $identifier,
            identifierKind: $row['abstractKind'],
            lifetime: ResolvesToLifetime::fromBindsToType(
                BindsToType::assertAllowed($row['binds_to_type']),
            ),
            via: $row['facade_class'],
            file: '',
            line: 0,
        );
    }

    public function fromFacadeCatalogEntry(ResolutionCatalogEntry $entry): array
    {
        $facadeClass = $entry->facadeClass ?? $entry->identifier;
        $identifier = $entry->bindingKey !== null && $entry->bindingKey !== ''
            ? $entry->bindingKey
            : $entry->abstract;

        return $this->chain(
            instance: '',
            access: DependencyAccessType::Facade,
            identifier: $identifier,
            identifierKind: $this->kindForIdentifier($entry->abstract),
            lifetime: ResolvesToLifetime::fromBindsToType($entry->bindsToType),
            via: $facadeClass,
            file: '',
            line: 0,
        );
    }

    /**
     * @return array{
     *     instance: string,
     *     dependency_key: string,
     *     access: string,
     *     identifier: string,
     *     identifier_kind: string,
     *     lifetime: string,
     *     via: string,
     *     file: string,
     *     line: int
     * }
     */
    private function chain(
        string $instance,
        DependencyAccessType $access,
        string $identifier,
        string $identifierKind,
        ResolvesToLifetime $lifetime,
        string $via,
        string $file,
        int $line,
    ): array {
        $dependencyKey = $access->value.'|'.$identifier;

        return [
            'instance' => $instance,
            'dependency_key' => $dependencyKey,
            'access' => $access->value,
            'identifier' => $identifier,
            'identifier_kind' => $identifierKind,
            'lifetime' => $lifetime->value,
            'via' => $via,
            'file' => $file,
            'line' => $line,
        ];
    }

    private function kindForIdentifier(string $identifier): string
    {
        if (interface_exists($identifier)) {
            return 'Interface';
        }

        if (class_exists($identifier)) {
            return 'Class';
        }

        return 'Alias';
    }
}
