<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Neo4j\LaravelBoost\ClassDependencyGraphReader;
use Neo4j\LaravelBoost\GraphKnowledgeContributor;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Support\Graph\GraphCompleteness;
use Neo4j\LaravelBoost\Support\Graph\RelationshipTypeReader;
use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;

/**
 * In-memory graph reader backed by container:graph export rows (for E2E tests).
 */
class InMemoryClassDependencyGraphReader extends ClassDependencyGraphReader
{
    /** @var array<int, string> */
    private array $classes = [];

    /** @var array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}> */
    private array $bindingRows = [];

    /** @var array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string, injection_type?: string, method?: string, parameter?: string, helper?: string, source?: string, confidence?: string, provenance?: string, remarks?: string}> */
    private array $dependencyChainRows = [];

    /** @var array<int, array<string, mixed>> */
    private array $contributedRows = [];

    /**
     * @param  array<int, array{class: string}>  $instanceRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int}>  $dependencyChainRows
     */
    public static function fromExportRows(
        array $instanceRows,
        array $bindingRows,
        array $dependencyChainRows,
    ): self {
        $reader = new self(new UnusedContainerGraphConnection);
        $reader->classes = array_map(static fn (array $row): string => $row['class'], $instanceRows);
        $reader->bindingRows = $bindingRows;
        $reader->dependencyChainRows = array_values(array_filter(
            $dependencyChainRows,
            static fn (array $row): bool => ($row['instance'] ?? '') !== '',
        ));

        foreach ($bindingRows as $row) {
            $reader->classes[] = $row['abstract'];
            $reader->classes[] = $row['concrete'];
        }

        foreach ($reader->dependencyChainRows as $row) {
            $reader->classes[] = $row['instance'];
            $reader->classes[] = $row['identifier'];
        }

        $reader->classes = array_values(array_unique($reader->classes));

        return $reader;
    }

    /**
     * @param  array<string, mixed>  $contribution
     */
    public function addContribution(array $contribution): void
    {
        $this->contributedRows[] = $contribution;

        $from = (string) ($contribution['from'] ?? '');
        $to = (string) ($contribution['to'] ?? '');

        if ($from !== '') {
            $this->classes[] = $from;
        }

        if ($to !== '') {
            $this->classes[] = $to;
        }

        $this->classes = array_values(array_unique($this->classes));
    }

    public function __construct(ContainerGraphConnection $connection)
    {
        parent::__construct($connection);
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function getGraph(
        string $class,
        int $depth = 4,
        string $direction = 'outbound',
        bool $includeBindings = true,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): array {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);

        if (! $this->classExists($class)) {
            return [
                'class' => $class,
                'found' => false,
                'graph_export_required' => true,
                'message' => 'No container graph data for this class. Run: php artisan container:graph',
            ];
        }

        $result = [
            'class' => $class,
            'found' => true,
            'graph_export_required' => false,
            'graph_completeness' => GraphCompleteness::partial(),
        ];

        if ($includeBindings) {
            $binding = $this->findBindingForClass($class);
            if ($binding !== null) {
                $result['binding'] = $binding;
            }
        }

        if ($direction === 'outbound' || $direction === 'both') {
            $entries = $this->traverseDependencyChains($class, $depth, outbound: true);
            $paginated = $this->paginateEntries($entries, $page, $perPage);
            $result['dependencies'] = $paginated['items'];
            $result['dependencies_pagination'] = $paginated['pagination'];
        }

        if ($direction === 'inbound' || $direction === 'both') {
            $entries = $this->traverseDependencyChains($class, $depth, outbound: false);
            $paginated = $this->paginateEntries($entries, $page, $perPage);
            $result['dependents'] = $paginated['items'];
            $result['dependents_pagination'] = $paginated['pagination'];
        }

        return $result;
    }

    private function classExists(string $class): bool
    {
        return in_array($class, $this->classes, true);
    }

    /**
     * @return null|array{abstract: string, concrete: string, shared: bool, type: string, source?: string, confidence?: string, provenance?: string, remarks?: string}
     */
    private function findBindingForClass(string $class): ?array
    {
        foreach ($this->bindingRows as $row) {
            if ($row['abstract'] === $class) {
                return $this->formatBindingRow($row);
            }
        }

        foreach ($this->bindingRows as $row) {
            if ($row['concrete'] === $class) {
                return $this->formatBindingRow($row);
            }
        }

        foreach ($this->contributedRows as $contribution) {
            if (($contribution['relationship'] ?? '') !== GraphKnowledgeContributor::RELATIONSHIP_BINDS_TO) {
                continue;
            }

            $from = (string) ($contribution['from'] ?? '');
            $to = (string) ($contribution['to'] ?? '');

            if ($from !== $class && $to !== $class) {
                continue;
            }

            $shared = (bool) ($contribution['shared'] ?? false);
            $typeMeta = RelationshipTypeReader::bindsTo(
                (string) ($contribution['type'] ?? BindsToType::fromShared($shared)->value),
            );

            return [
                'abstract' => $from,
                'concrete' => $to,
                'shared' => $typeMeta['shared'],
                'type' => $typeMeta['type'],
                ...$this->edgeMetadataFromRow([
                    'source' => $contribution['source'] ?? '',
                    'confidence' => $contribution['confidence'] ?? 'high',
                    'provenance' => '',
                    'remarks' => $contribution['reason'] ?? '',
                ]),
            ];
        }

        return null;
    }

    /**
     * @param  array{abstract: string, concrete: string, shared: bool, type: string}  $row
     * @return array{abstract: string, concrete: string, shared: bool, type: string, source?: string, confidence?: string, provenance?: string, remarks?: string}
     */
    private function formatBindingRow(array $row): array
    {
        $typeMeta = RelationshipTypeReader::bindsTo($row['type']);

        return [
            'abstract' => $row['abstract'],
            'concrete' => $row['concrete'],
            'shared' => $typeMeta['shared'],
            'type' => $typeMeta['type'],
            ...$this->edgeMetadataFromRow($row),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function traverseDependencyChains(string $class, int $depth, bool $outbound): array
    {
        $entries = [];
        $visited = [];
        $frontier = [$class];

        for ($currentDepth = 1; $currentDepth <= $depth; $currentDepth++) {
            $nextFrontier = [];

            foreach ($frontier as $nodeName) {
                $chains = $outbound
                    ? $this->directOutboundChains($nodeName)
                    : $this->directInboundChains($nodeName);

                foreach ($chains as $chain) {
                    $targetName = (string) $chain['name'];
                    $key = $targetName.'@'.$currentDepth;

                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;
                    $entries[] = array_merge($chain, ['depth' => $currentDepth]);

                    if ($this->instanceExists($targetName)) {
                        $nextFrontier[] = $targetName;
                    }
                }
            }

            $frontier = array_values(array_unique($nextFrontier));
        }

        return $this->sortEntries($this->uniqueEntries($entries));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function directOutboundChains(string $instance): array
    {
        $entries = [];

        foreach ($this->dependencyChainRows as $row) {
            if ($row['instance'] !== $instance) {
                continue;
            }

            $entries[] = $this->entryFromChainRow($row);
        }

        foreach ($this->contributedRows as $contribution) {
            if (($contribution['relationship'] ?? '') !== GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON) {
                continue;
            }

            if (($contribution['from'] ?? '') !== $instance) {
                continue;
            }

            $to = (string) ($contribution['to'] ?? '');
            if ($to === '') {
                continue;
            }

            $injectionType = (string) ($contribution['type'] ?? DependsOnType::ServiceLocation->value);
            $access = DependencyAccessType::fromDependsOnType($injectionType);

            $entry = [
                'name' => $to,
                'kind' => $this->kindForTypeName($to),
                'relationship' => 'DEPENDS_ON',
                'access' => $access->value,
                'lifetime' => ResolvesToLifetime::Bind->value,
                'type' => $injectionType,
            ];

            $entries[] = array_merge($entry, $this->edgeMetadataFromRow([
                'source' => $contribution['source'] ?? '',
                'confidence' => $contribution['confidence'] ?? 'high',
                'provenance' => '',
                'remarks' => $contribution['reason'] ?? '',
            ]));
        }

        return $entries;
    }

    /**
     * @param  array{instance: string, dependency_key: string, access: string, identifier: string, identifier_kind: string, lifetime: string, via: string, file: string, line: int, reason?: string, injection_type?: string, method?: string, parameter?: string, helper?: string, source?: string, confidence?: string, provenance?: string, remarks?: string}  $row
     * @return array<string, mixed>
     */
    private function entryFromChainRow(array $row): array
    {
        $access = DependencyAccessType::assertAllowed($row['access']);
        $kind = $row['identifier_kind'] === 'Unresolved' ? 'UnresolvedDependency' : $row['identifier_kind'];

        $entry = [
            'name' => $row['identifier'],
            'kind' => $kind,
            'relationship' => 'DEPENDS_ON',
            'access' => $access->value,
            'lifetime' => $row['lifetime'],
        ];

        if ($kind === 'UnresolvedDependency') {
            $entry['reason'] = $row['reason'] ?? 'unresolved';
        }

        if ($row['via'] !== '') {
            $entry['via'] = $row['via'];
        }

        if ($row['file'] !== '') {
            $entry['file'] = $row['file'];
        }

        if ($row['line'] > 0) {
            $entry['line'] = $row['line'];
        }

        if (($row['injection_type'] ?? '') !== '') {
            $entry['type'] = $row['injection_type'];
        }

        if (($row['method'] ?? '') !== '') {
            $entry['method'] = $row['method'];
        }

        if (($row['parameter'] ?? '') !== '') {
            $entry['parameter'] = $row['parameter'];
        }

        if (($row['helper'] ?? '') !== '') {
            $entry['helper'] = $row['helper'];
        }

        return array_merge($entry, $this->edgeMetadataFromRow($row));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function directInboundChains(string $identifier): array
    {
        $entries = [];

        foreach ($this->dependencyChainRows as $row) {
            if ($row['identifier'] !== $identifier) {
                continue;
            }

            $access = DependencyAccessType::assertAllowed($row['access']);

            $entry = [
                'name' => $row['instance'],
                'kind' => 'Class',
                'relationship' => 'DEPENDS_ON',
                'access' => $access->value,
                'lifetime' => $row['lifetime'],
            ];

            if (($row['via'] ?? '') !== '') {
                $entry['via'] = $row['via'];
            }

            if (($row['file'] ?? '') !== '') {
                $entry['file'] = $row['file'];
            }

            if (($row['line'] ?? 0) > 0) {
                $entry['line'] = $row['line'];
            }

            if (($row['injection_type'] ?? '') !== '') {
                $entry['type'] = $row['injection_type'];
            }

            if (($row['method'] ?? '') !== '') {
                $entry['method'] = $row['method'];
            }

            if (($row['parameter'] ?? '') !== '') {
                $entry['parameter'] = $row['parameter'];
            }

            if (($row['helper'] ?? '') !== '') {
                $entry['helper'] = $row['helper'];
            }

            $entries[] = array_merge($entry, $this->edgeMetadataFromRow($row));
        }

        return $entries;
    }

    private function kindForTypeName(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }

    private function instanceExists(string $name): bool
    {
        foreach ($this->dependencyChainRows as $row) {
            if ($row['instance'] === $name) {
                return true;
            }
        }

        return in_array($name, $this->classes, true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{source: string, confidence: string, provenance: string, remarks?: string}
     */
    private function edgeMetadataFromRow(array $row): array
    {
        $metadata = [
            'source' => (string) ($row['source'] ?? ''),
            'confidence' => (string) ($row['confidence'] ?? ''),
            'provenance' => (string) ($row['provenance'] ?? ''),
        ];

        $remarks = (string) ($row['remarks'] ?? '');
        if ($remarks !== '') {
            $metadata['remarks'] = $remarks;
        }

        return $metadata;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function paginateEntries(array $entries, int $page, int $perPage): array
    {
        $total = count($entries);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($entries, $offset, $perPage),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function sortEntries(array $entries): array
    {
        usort($entries, static function (array $a, array $b): int {
            $depthCompare = ($a['depth'] ?? 0) <=> ($b['depth'] ?? 0);
            if ($depthCompare !== 0) {
                return $depthCompare;
            }

            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function uniqueEntries(array $entries): array
    {
        $seen = [];
        $unique = [];

        foreach ($entries as $entry) {
            $key = json_encode($entry);
            if ($key === false || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $entry;
        }

        return $unique;
    }
}
