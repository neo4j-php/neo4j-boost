<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
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
     * @param  array{class: string, dependency: string, dependencyKind: string, type: string, method?: string, parameter?: string, source?: string, via?: string, file?: string, line?: int}  $row
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
     *     line: int
     * }
     */
    public function fromExtractedDependencyRow(array $row, array $bindings): array
    {
        $injectionType = (string) $row['type'];
        $access = DependencyAccessType::fromDependsOnType($injectionType);
        $identifier = (string) $row['dependency'];
        $identifierKind = (string) ($row['dependencyKind'] ?? $this->kindForIdentifier($identifier));
        $method = (string) ($row['method'] ?? '');
        $parameter = (string) ($row['parameter'] ?? '');

        return $this->chain(
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

        return $chain;
    }

    /**
     * Catalog-only facade resolution path (no Instance). Used when the resolution
     * catalog branch is merged; accepts FacadeCatalogExporter row shape.
     *
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
     *     line: int
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
            injectionType: '',
            method: '',
            parameter: '',
            via: $row['facade_class'],
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
     *     injection_type: string,
     *     method: string,
     *     parameter: string,
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
        string $injectionType,
        string $method,
        string $parameter,
        string $via,
        string $file,
        int $line,
    ): array {
        return [
            'instance' => $instance,
            'dependency_key' => $this->dependencyKey($access, $identifier, $injectionType, $method, $parameter),
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
        ];
    }

    private function dependencyKey(
        DependencyAccessType $access,
        string $identifier,
        string $injectionType,
        string $method,
        string $parameter,
    ): string {
        if ($injectionType === DependsOnType::MethodInjection->value) {
            return $access->value.'|'.$identifier.'|'.$method.'|'.$parameter;
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
