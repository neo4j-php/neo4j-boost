<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Neo4j\LaravelBoost\ClassDependencyGraphReader;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\GraphCompleteness;
use Neo4j\LaravelBoost\Support\Graph\RelationshipTypeReader;
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

    /** @var array<int, array{class: string, dependency: string, dependencyKind: string, type: string}> */
    private array $dependencyRows = [];

    /** @var array<int, array{class: string, name: string, reason: string, type: string}> */
    private array $unresolvedRows = [];

    /**
     * @param  array<int, array{class: string}>  $classRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string}>  $dependencyRows
     * @param  array<int, array{class: string, name: string, reason: string, type: string}>  $unresolvedRows
     */
    public static function fromExportRows(
        array $classRows,
        array $bindingRows,
        array $dependencyRows,
        array $unresolvedRows,
    ): self {
        $reader = new self(new UnusedContainerGraphConnection);
        $reader->classes = array_map(static fn (array $row): string => $row['class'], $classRows);
        $reader->bindingRows = $bindingRows;
        $reader->dependencyRows = $dependencyRows;
        $reader->unresolvedRows = $unresolvedRows;

        foreach ($bindingRows as $row) {
            $reader->classes[] = $row['abstract'];
            $reader->classes[] = $row['concrete'];
        }

        foreach ($dependencyRows as $row) {
            $reader->classes[] = $row['class'];
            $reader->classes[] = $row['dependency'];
        }

        foreach ($unresolvedRows as $row) {
            $reader->classes[] = $row['class'];
            $reader->classes[] = $row['name'];
        }

        $reader->classes = array_values(array_unique($reader->classes));

        return $reader;
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
        ];

        if ($includeBindings) {
            $binding = $this->findBindingForClass($class);
            if ($binding !== null) {
                $result['binding'] = $binding;
            }
        }

        if ($direction === 'outbound' || $direction === 'both') {
            $entries = $this->traverseDependencies($class, $depth);
            $paginated = $this->paginateEntries($entries, $page, $perPage);
            $result['dependencies'] = $paginated['items'];
            $result['dependencies_pagination'] = $paginated['pagination'];
            $result = $this->appendDependencyBuckets($result, $paginated['items']);

            $visibilitySplit = $this->splitDependenciesByVisibility($entries);
            $result['graph_completeness'] = GraphCompleteness::build(
                count($visibilitySplit['declared']),
                count($visibilitySplit['hidden']),
            );
        }

        if ($direction === 'inbound' || $direction === 'both') {
            $entries = $this->traverseDependents($class, $depth);
            $paginated = $this->paginateEntries($entries, $page, $perPage);
            $result['dependents'] = $paginated['items'];
            $result['dependents_pagination'] = $paginated['pagination'];
        }

        return $this->finalizeResponse($result);
    }

    private function classExists(string $class): bool
    {
        return in_array($class, $this->classes, true);
    }

    /**
     * @return null|array{abstract: string, concrete: string, shared: bool, type: string, source: string, confidence: string}
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

        return null;
    }

    /**
     * @param  array{abstract: string, concrete: string, shared: bool, type: string, source?: string}  $row
     * @return array{abstract: string, concrete: string, shared: bool, type: string, source: string, confidence: string}
     */
    private function formatBindingRow(array $row): array
    {
        $typeMeta = RelationshipTypeReader::bindsTo(
            $row['type'] ?? null,
            $row['shared'] ?? null,
            $row['source'] ?? null,
        );

        return [
            'abstract' => $row['abstract'],
            'concrete' => $row['concrete'],
            'shared' => $typeMeta['shared'],
            'type' => $typeMeta['type'],
            'source' => $typeMeta['source'],
            'confidence' => $typeMeta['confidence'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function traverseDependencies(string $class, int $depth): array
    {
        $entries = [];
        $this->walkDependencies($class, 1, $depth, $entries);

        foreach ($this->unresolvedRows as $row) {
            if ($row['class'] !== $class) {
                continue;
            }

            $entries[] = [
                'name' => $row['name'],
                'kind' => 'UnresolvedDependency',
                'relationship' => 'DEPENDS_ON',
                'reason' => $row['reason'],
                'depth' => 1,
                ...RelationshipTypeReader::dependsOn($row['type'] ?? null, $row['source'] ?? null),
            ];
        }

        return $this->sortEntries($this->uniqueEntries($entries));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function traverseDependents(string $class, int $depth): array
    {
        $entries = [];
        $this->walkDependents($class, 1, $depth, $entries);

        return $this->sortEntries($this->uniqueEntries($entries));
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function walkDependencies(string $class, int $currentDepth, int $maxDepth, array &$entries): void
    {
        if ($currentDepth > $maxDepth) {
            return;
        }

        foreach ($this->dependencyRows as $row) {
            if ($row['class'] !== $class) {
                continue;
            }

            $entries[] = [
                'name' => $row['dependency'],
                'kind' => $row['dependencyKind'],
                'relationship' => 'DEPENDS_ON',
                'depth' => $currentDepth,
                ...RelationshipTypeReader::dependsOn($row['type'] ?? null, $row['source'] ?? null),
            ];

            $this->walkDependencies($row['dependency'], $currentDepth + 1, $maxDepth, $entries);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function walkDependents(string $class, int $currentDepth, int $maxDepth, array &$entries): void
    {
        if ($currentDepth > $maxDepth) {
            return;
        }

        foreach ($this->dependencyRows as $row) {
            if ($row['dependency'] !== $class) {
                continue;
            }

            $entries[] = [
                'name' => $row['class'],
                'kind' => 'Class',
                'relationship' => 'DEPENDS_ON',
                'depth' => $currentDepth,
                ...RelationshipTypeReader::dependsOn($row['type'] ?? null, $row['source'] ?? null),
            ];

            $this->walkDependents($row['class'], $currentDepth + 1, $maxDepth, $entries);
        }
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
