<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\DependencyAccessType;
use Neo4j\LaravelBoost\Support\Graph\DependencyVisibility;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Support\Graph\GraphCompleteness;
use Neo4j\LaravelBoost\Support\Graph\RelationshipTypeReader;

class ClassDependencyGraphReader
{
    public const DEFAULT_PER_PAGE = 100;

    public const MAX_PER_PAGE = 500;

    public function __construct(
        private ContainerGraphConnection $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
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

        if (! $this->classExistsInGraph($class)) {
            return $this->finalizeResponse([
                'class' => $class,
                'found' => false,
                'graph_export_required' => true,
                'message' => 'No container graph data for this class. Run: php artisan container:graph',
            ]);
        }

        $result = [
            'class' => $class,
            'found' => true,
            'graph_export_required' => false,
            'graph_completeness' => GraphCompleteness::partial(),
        ];

        if ($includeBindings) {
            $binding = $this->fetchBinding($class);
            if ($binding !== null) {
                $result['binding'] = $binding;
            }
        }

        if ($direction === 'outbound' || $direction === 'both') {
            $allEntries = $this->traverseDependencyChains($class, $depth, outbound: true);
            $total = count($allEntries);
            $items = $this->uniqueDependencyEntries(
                array_slice($allEntries, ($page - 1) * $perPage, $perPage),
            );
            $result['dependencies'] = $items;
            $result['dependencies_pagination'] = $this->buildPaginationMeta($page, $perPage, $total);
            $result = $this->appendDependencyBuckets($result, $items);
            $result['graph_completeness'] = $this->buildGraphCompleteness($allEntries);
        }

        if ($direction === 'inbound' || $direction === 'both') {
            $paginated = $this->fetchDependents($class, $depth, $page, $perPage);
            $result['dependents'] = $paginated['items'];
            $result['dependents_pagination'] = $paginated['pagination'];
        }

        return $this->finalizeResponse($result);
    }

    private function classExistsInGraph(string $class): bool
    {
        $result = $this->connection->run(
            <<<'CYPHER'
MATCH (n)
WHERE (n:Instance OR n:Abstract) AND n.name = $class
RETURN count(n) AS total
CYPHER,
            ['class' => $class],
        );

        $record = $result->first();
        if ($record === null) {
            return false;
        }

        return (int) $record->get('total') > 0;
    }

    /**
     * @return null|array{abstract: string, concrete: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}
     */
    private function fetchBinding(string $class): ?array
    {
        $binding = $this->fetchBindingFromQuery(
            <<<'CYPHER'
MATCH (a:Abstract {name: $class})-[r:BINDS_TO]->(t:Abstract)
RETURN a.name AS abstract, t.name AS concrete, r.type AS type,
       r.source AS source, r.confidence AS confidence, r.provenance AS provenance, r.remarks AS remarks
LIMIT 1
CYPHER,
            ['class' => $class],
        );

        if ($binding !== null) {
            return $binding;
        }

        return $this->fetchBindingFromQuery(
            <<<'CYPHER'
MATCH (a:Abstract)-[r:BINDS_TO]->(t:Abstract {name: $class})
RETURN a.name AS abstract, t.name AS concrete, r.type AS type,
       r.source AS source, r.confidence AS confidence, r.provenance AS provenance, r.remarks AS remarks
LIMIT 1
CYPHER,
            ['class' => $class],
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return null|array{abstract: string, concrete: string, shared: bool, type: string, source: string, confidence: string, provenance: string, remarks: string}
     */
    private function fetchBindingFromQuery(string $cypher, array $parameters): ?array
    {
        $result = $this->connection->run($cypher, $parameters);

        foreach ($result as $record) {
            $typeMeta = RelationshipTypeReader::bindsTo($record->get('type'));

            return [
                'abstract' => (string) $record->get('abstract'),
                'concrete' => (string) $record->get('concrete'),
                'shared' => $typeMeta['shared'],
                'type' => $typeMeta['type'],
                ...$this->bindingMetadataFromRecord($record),
            ];
        }

        return null;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function fetchDependents(string $class, int $depth, int $page, int $perPage): array
    {
        $allEntries = $this->traverseDependencyChains($class, $depth, outbound: false);
        $total = count($allEntries);

        return [
            'items' => $this->uniqueDependencyEntries(array_slice($allEntries, ($page - 1) * $perPage, $perPage)),
            'pagination' => $this->buildPaginationMeta($page, $perPage, $total),
        ];
    }

    /**
     * @return array{page: int, per_page: int, total: int, last_page: int, has_more: bool}
     */
    private function buildPaginationMeta(int $page, int $perPage, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
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
                    ? $this->fetchDirectOutboundChains($nodeName)
                    : $this->fetchDirectInboundChains($nodeName);

                foreach ($chains as $chain) {
                    $targetName = (string) $chain['name'];
                    $key = $targetName.'@'.$currentDepth;

                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;
                    $entries[] = array_merge($chain, ['depth' => $currentDepth]);

                    if ($outbound && $this->instanceExists($targetName)) {
                        $nextFrontier[] = $targetName;
                    } elseif (! $outbound && $this->instanceExists($targetName)) {
                        $nextFrontier[] = $targetName;
                    }
                }
            }

            $frontier = array_values(array_unique($nextFrontier));
        }

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
     * @return array<int, array<string, mixed>>
     */
    private function fetchDirectOutboundChains(string $instance): array
    {
        $result = $this->connection->run(
            <<<'CYPHER'
MATCH (i:Instance {name: $instance})-[d:DEPENDS_ON]->(dep:Dependency)-[r:RESOLVES_TO]->(id:Identifier)
RETURN id.name AS name, id.kind AS kind, id.reason AS reason, dep.access AS access,
       r.lifetime AS lifetime, d.via AS via, d.file AS file, d.line AS line,
       d.type AS injection_type, d.method AS method, d.parameter AS parameter, d.helper AS helper,
       d.source AS source, d.confidence AS confidence, d.provenance AS provenance, d.remarks AS remarks
ORDER BY id.name ASC
CYPHER,
            ['instance' => $instance],
        );

        return $this->mapChainRecords($result);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDirectInboundChains(string $identifier): array
    {
        $result = $this->connection->run(
            <<<'CYPHER'
MATCH (i:Instance)-[d:DEPENDS_ON]->(dep:Dependency)-[r:RESOLVES_TO]->(id:Identifier {name: $identifier})
RETURN i.name AS name, id.kind AS kind, id.reason AS reason, dep.access AS access,
       r.lifetime AS lifetime, d.via AS via, d.file AS file, d.line AS line,
       d.type AS injection_type, d.method AS method, d.parameter AS parameter, d.helper AS helper,
       d.source AS source, d.confidence AS confidence, d.provenance AS provenance, d.remarks AS remarks
ORDER BY i.name ASC
CYPHER,
            ['identifier' => $identifier],
        );

        return $this->mapInboundChainRecords($result);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapInboundChainRecords(iterable $result): array
    {
        $entries = [];

        foreach ($result as $record) {
            $access = DependencyAccessType::assertAllowed((string) $record->get('access'));

            $entry = [
                'name' => (string) $record->get('name'),
                'kind' => 'Class',
                'relationship' => 'DEPENDS_ON',
                'access' => $access->value,
                'lifetime' => (string) $record->get('lifetime'),
            ];

            $via = (string) $record->get('via');
            if ($via !== '') {
                $entry['via'] = $via;
            }

            $file = (string) $record->get('file');
            if ($file !== '') {
                $entry['file'] = $file;
            }

            $line = (int) $record->get('line');
            if ($line > 0) {
                $entry['line'] = $line;
            }

            $injectionType = (string) $record->get('injection_type');
            if ($injectionType !== '') {
                $entry['type'] = $injectionType;
            }

            $method = (string) $record->get('method');
            if ($method !== '') {
                $entry['method'] = $method;
            }

            $parameter = (string) $record->get('parameter');
            if ($parameter !== '') {
                $entry['parameter'] = $parameter;
            }

            $helper = (string) $record->get('helper');
            if ($helper !== '') {
                $entry['helper'] = $helper;
            }

            $entry = array_merge($entry, $this->edgeMetadataFromRecord($record));
            $entries[] = $this->withDependencyMetadata($entry, $injectionType, $access);
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapChainRecords(iterable $result): array
    {
        $entries = [];

        foreach ($result as $record) {
            $kind = (string) $record->get('kind');
            $access = DependencyAccessType::assertAllowed((string) $record->get('access'));

            $entry = [
                'name' => (string) $record->get('name'),
                'kind' => $kind === 'Unresolved' ? 'UnresolvedDependency' : $kind,
                'relationship' => 'DEPENDS_ON',
                'access' => $access->value,
                'lifetime' => (string) $record->get('lifetime'),
            ];

            if ($kind === 'Unresolved') {
                $entry['reason'] = (string) $record->get('reason');
            }

            $via = (string) $record->get('via');
            if ($via !== '') {
                $entry['via'] = $via;
            }

            $file = (string) $record->get('file');
            if ($file !== '') {
                $entry['file'] = $file;
            }

            $line = (int) $record->get('line');
            if ($line > 0) {
                $entry['line'] = $line;
            }

            $injectionType = (string) $record->get('injection_type');
            if ($injectionType !== '') {
                $entry['type'] = $injectionType;
            }

            $method = (string) $record->get('method');
            if ($method !== '') {
                $entry['method'] = $method;
            }

            $parameter = (string) $record->get('parameter');
            if ($parameter !== '') {
                $entry['parameter'] = $parameter;
            }

            $helper = (string) $record->get('helper');
            if ($helper !== '') {
                $entry['helper'] = $helper;
            }

            $entry = array_merge($entry, $this->edgeMetadataFromRecord($record));
            $entries[] = $this->withDependencyMetadata($entry, $injectionType, $access);
        }

        return $entries;
    }

    private function instanceExists(string $name): bool
    {
        $result = $this->connection->run(
            'MATCH (i:Instance {name: $name}) RETURN count(i) AS total',
            ['name' => $name],
        );

        $record = $result->first();

        return $record !== null && (int) $record->get('total') > 0;
    }

    /**
     * @return array{source: string, confidence: string, provenance: string, remarks?: string}
     */
    private function edgeMetadataFromRecord(object $record): array
    {
        $metadata = [
            'source' => (string) $record->get('source'),
            'confidence' => (string) $record->get('confidence'),
            'provenance' => (string) $record->get('provenance'),
        ];

        $remarks = (string) $record->get('remarks');
        if ($remarks !== '') {
            $metadata['remarks'] = $remarks;
        }

        return $metadata;
    }

    /**
     * @return array{source: string, confidence: string, provenance: string, remarks?: string}
     */
    private function bindingMetadataFromRecord(object $record): array
    {
        return $this->edgeMetadataFromRecord($record);
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function uniqueDependencyEntries(array $entries): array
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

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function withDependencyMetadata(array $entry, string $injectionType, DependencyAccessType $access): array
    {
        $effectiveType = $injectionType !== ''
            ? $injectionType
            : $this->dependsOnTypeFromAccess($access);

        $entry['visibility'] = DependencyVisibility::fromDependsOnType(
            DependsOnType::assertAllowed($effectiveType)
        )->value;

        return $entry;
    }

    protected function dependsOnTypeFromAccess(DependencyAccessType $access): string
    {
        return match ($access) {
            DependencyAccessType::Facade => DependsOnType::Facade->value,
            DependencyAccessType::GlobalHelper => DependsOnType::GlobalHelper->value,
            DependencyAccessType::ServiceLocation => DependsOnType::ServiceLocation->value,
            DependencyAccessType::Di => DependsOnType::ConstructorInjection->value,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $dependencies
     * @return array<string, mixed>
     */
    protected function appendDependencyBuckets(array $result, array $dependencies): array
    {
        $declared = [];
        $hidden = [];

        foreach ($dependencies as $dependency) {
            if (($dependency['visibility'] ?? null) === DependencyVisibility::Hidden->value) {
                $hidden[] = $dependency;
            } else {
                $declared[] = $dependency;
            }
        }

        $result['declared_dependencies'] = $declared;
        $result['hidden_dependencies'] = $hidden;

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{declared_count: int, hidden_count: int, total_count: int, coverage: string, detectors_active: list<string>, detectors_pending: list<string>}
     */
    protected function buildGraphCompleteness(array $entries): array
    {
        $declaredNames = [];
        $hiddenNames = [];

        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (($entry['visibility'] ?? null) === DependencyVisibility::Hidden->value) {
                $hiddenNames[$name] = true;
            } else {
                $declaredNames[$name] = true;
            }
        }

        return GraphCompleteness::build(count($declaredNames), count($hiddenNames));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function finalizeResponse(array $result): array
    {
        if (! isset($result['graph_completeness'])) {
            $result['graph_completeness'] = ($result['found'] ?? false)
                ? GraphCompleteness::empty()
                : GraphCompleteness::unknown();
        }

        return $result;
    }
}
