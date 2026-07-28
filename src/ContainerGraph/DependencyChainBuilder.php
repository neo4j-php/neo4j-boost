<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;

/**
 * Builds Instance → Dependency → Identifier export rows (SOFT-58).
 *
 * @phpstan-type DependencyChainRow array{
 *     instance: string,
 *     dependency_key: string,
 *     access: string,
 *     identifier: string,
 *     identifier_kind: string,
 *     lifetime: string,
 *     injection_type: string,
 *     method: string,
 *     parameter: string,
 *     via: string,
 *     file: string,
 *     line: int,
 *     catalog_source: string,
 *     source: string,
 *     confidence: string,
 *     provenance: string,
 *     remarks: string,
 *     helper?: string,
 *     reason?: string
 * }
 */
final class DependencyChainBuilder
{
    public function __construct(
        private BindingLifetimeResolver $lifetimeResolver,
        private DependencyEdgeMetadataResolver $metadataResolver,
    ) {}

    /**
     * @param  array<string, array{concrete: mixed, shared: bool}>  $bindings
     * @param  array{class: string, dependency: string, dependencyKind: string, type: string, method?: string, parameter?: string, source?: string, via?: string, file?: string, line?: int, helper?: string, catalog_source?: string}  $row
     * @return DependencyChainRow
     */
    public function fromExtractedDependencyRow(array $row, array $bindings): array
    {
        $injectionType = (string) $row['type'];
        $access = DependencyAccessType::fromDependsOnType($injectionType);
        $identifier = (string) $row['dependency'];
        $identifierKind = (string) ($row['dependencyKind'] ?? $this->kindForIdentifier($identifier));
        $method = (string) ($row['method'] ?? '');
        $parameter = (string) ($row['parameter'] ?? '');
        $helper = (string) ($row['helper'] ?? '');

        $chain = $this->chain(
            instance: (string) $row['class'],
            access: $access,
            identifier: $identifier,
            identifierKind: $identifierKind,
            lifetime: $this->lifetimeResolver->forIdentifier($identifier, $bindings),
            injectionType: $injectionType,
            method: $method,
            parameter: $parameter,
            via: (string) ($row['via'] ?? ''),
            file: (string) ($row['file'] ?? ''),
            line: (int) ($row['line'] ?? 0),
            helper: $helper,
            catalogSource: (string) ($row['catalog_source'] ?? ''),
        );

        if ($helper !== '') {
            $chain['helper'] = $helper;
        }

        if (isset($row['reason']) && is_string($row['reason'])) {
            $chain['reason'] = $row['reason'];
        }

        return array_merge($chain, $this->metadataResolver->forExtractedRow($row));
    }

    /**
     * @return DependencyChainRow
     */
    public function fromUnresolvedRow(array $row, array $bindings): array
    {
        $identifier = (string) $row['name'];

        $injectionType = (string) $row['type'];

        $chain = $this->chain(
            instance: (string) $row['class'],
            access: DependencyAccessType::fromDependsOnType($injectionType),
            identifier: $identifier,
            identifierKind: 'Unresolved',
            lifetime: $this->lifetimeResolver->forIdentifier($identifier, $bindings),
            injectionType: $injectionType,
            method: (string) ($row['method'] ?? ''),
            parameter: (string) ($row['parameter'] ?? ''),
            via: 'unresolved',
            file: '',
            line: 0,
        );
        $chain['reason'] = (string) ($row['reason'] ?? 'unresolved');

        return array_merge($chain, $this->metadataResolver->forUnresolvedRow($row));
    }

    /**
     * Catalog-only facade resolution path (no Instance). Used when the resolution
     * catalog branch is merged; accepts FacadeCatalogExporter row shape.
     *
     * @param  array{facade_class: string, abstract: string, abstractKind: string, binding_key: string, source: string, binds_to_type: string}  $row
     * @return DependencyChainRow
     */
    public function fromFacadeExportRow(array $row): array
    {
        $identifier = $row['binding_key'] !== '' ? $row['binding_key'] : $row['abstract'];

        return array_merge(
            $this->chain(
                instance: '',
                access: DependencyAccessType::Facade,
                identifier: $identifier,
                identifierKind: $row['abstractKind'],
                lifetime: ResolvesToLifetime::fromBindsToType(
                    BindsToType::assertAllowed($row['binds_to_type']),
                ),
                injectionType: '',
                method: '',
                parameter: '',
                via: $row['facade_class'],
                file: '',
                line: 0,
                catalogSource: $row['source'],
            ),
            $this->metadataResolver->forFacadeCatalogRow($row),
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
     *     injection_type: string,
     *     method: string,
     *     parameter: string,
     *     via: string,
     *     file: string,
     *     line: int,
     *     catalog_source: string
     * }
     */
    private function chain(
        string $instance,
        DependencyAccessType $access,
        string $identifier,
        string $identifierKind,
        ResolvesToLifetime $lifetime,
        string $injectionType,
        string $method,
        string $parameter,
        string $via,
        string $file,
        int $line,
        string $helper = '',
        string $catalogSource = '',
    ): array {
        return [
            'instance' => $instance,
            'dependency_key' => $this->dependencyKey($access, $identifier, $injectionType, $method, $parameter, $helper),
            'access' => $access->value,
            'identifier' => $identifier,
            'identifier_kind' => $identifierKind,
            'lifetime' => $lifetime->value,
            'injection_type' => $injectionType,
            'method' => $method,
            'parameter' => $parameter,
            'via' => $via,
            'file' => $file,
            'line' => $line,
            'catalog_source' => $catalogSource,
        ];
    }

    private function dependencyKey(
        DependencyAccessType $access,
        string $identifier,
        string $injectionType,
        string $method,
        string $parameter,
        string $helper = '',
    ): string {
        if ($injectionType === DependsOnType::MethodInjection->value) {
            return $access->value.'|'.$identifier.'|'.$method.'|'.$parameter;
        }

        if ($injectionType === DependsOnType::GlobalHelper->value && $helper !== '') {
            return $access->value.'|'.$identifier.'|'.$helper;
        }

        return $access->value.'|'.$identifier;
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
