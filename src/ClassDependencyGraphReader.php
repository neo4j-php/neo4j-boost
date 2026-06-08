<?php

namespace Neo4j\LaravelBoost;

use Laudis\Neo4j\Types\CypherList;
use Neo4j\LaravelBoost\Support\ContainerGraphConnection;

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
        ];

        if ($includeBindings) {
            $binding = $this->fetchBinding($class);
            if ($binding !== null) {
                $result['binding'] = $binding;
            }
        }

        if ($direction === 'outbound' || $direction === 'both') {
            $paginated = $this->fetchDependencies($class, $depth, $page, $perPage);
            $result['dependencies'] = $paginated['items'];
            $result['dependencies_pagination'] = $paginated['pagination'];
        }

        if ($direction === 'inbound' || $direction === 'both') {
            $paginated = $this->fetchDependents($class, $depth, $page, $perPage);
            $result['dependents'] = $paginated['items'];
            $result['dependents_pagination'] = $paginated['pagination'];
        }

        return $result;
    }

    private function classExistsInGraph(string $class): bool
    {
        $result = $this->connection->run(
            'MATCH (n:Abstract {name: $class}) RETURN count(n) AS total',
            ['class' => $class],
        );

        $record = $result->first();
        if ($record === null) {
            return false;
        }

        return (int) $record->get('total') > 0;
    }

    /**
     * @return null|array{abstract: string, concrete: string, shared: bool}
     */
    private function fetchBinding(string $class): ?array
    {
        $binding = $this->fetchBindingFromQuery(
            <<<'CYPHER'
MATCH (a:Abstract {name: $class})-[r:BINDS_TO]->(t:Abstract)
RETURN a.name AS abstract, t.name AS concrete, r.shared AS shared
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
RETURN a.name AS abstract, t.name AS concrete, r.shared AS shared
LIMIT 1
CYPHER,
            ['class' => $class],
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return null|array{abstract: string, concrete: string, shared: bool}
     */
    private function fetchBindingFromQuery(string $cypher, array $parameters): ?array
    {
        $result = $this->connection->run($cypher, $parameters);

        foreach ($result as $record) {
            return [
                'abstract' => (string) $record->get('abstract'),
                'concrete' => (string) $record->get('concrete'),
                'shared' => (bool) $record->get('shared'),
            ];
        }

        return null;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function fetchDependencies(string $class, int $depth, int $page, int $perPage): array
    {
        $unresolved = $this->fetchUnresolvedDependencies($class);
        $resolvedTotal = $this->countDependencyPaths($class, $depth, outbound: true);
        $total = $resolvedTotal + count($unresolved);

        return $this->paginateMergedEntries(
            $unresolved,
            fn (int $skip, int $limit): array => $this->fetchDependencyPaths($class, $depth, outbound: true, skip: $skip, limit: $limit),
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function fetchDependents(string $class, int $depth, int $page, int $perPage): array
    {
        $total = $this->countDependencyPaths($class, $depth, outbound: false);

        return $this->paginateMergedEntries(
            [],
            fn (int $skip, int $limit): array => $this->fetchDependencyPaths($class, $depth, outbound: false, skip: $skip, limit: $limit),
            $page,
            $perPage,
            $total,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $prefixEntries
     * @param  callable(int, int): array<int, array<string, mixed>>  $fetchPage
     * @return array{items: array<int, array<string, mixed>>, pagination: array{page: int, per_page: int, total: int, last_page: int, has_more: bool}}
     */
    private function paginateMergedEntries(
        array $prefixEntries,
        callable $fetchPage,
        int $page,
        int $perPage,
        int $total,
    ): array {
        $prefixCount = count($prefixEntries);
        $offset = ($page - 1) * $perPage;
        $items = [];

        if ($offset < $prefixCount) {
            $items = array_slice($prefixEntries, $offset, $perPage);
        }

        $remaining = $perPage - count($items);
        if ($remaining > 0) {
            $resolvedSkip = max(0, $offset - $prefixCount);
            $items = array_merge($items, $fetchPage($resolvedSkip, $remaining));
        }

        return [
            'items' => $this->uniqueDependencyEntries($items),
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

    private function countDependencyPaths(string $class, int $depth, bool $outbound): int
    {
        $relationship = $outbound
            ? '-[:DEPENDS_ON*1..%d]->'
            : '<-[:DEPENDS_ON*1..%d]-';

        $cypher = sprintf(
            <<<'CYPHER'
MATCH (c:Abstract {name: $class})%s(d:Abstract)
RETURN count(DISTINCT d) AS total
CYPHER,
            sprintf($relationship, $depth),
        );

        $result = $this->connection->run($cypher, ['class' => $class]);
        $record = $result->first();

        if ($record === null) {
            return 0;
        }

        return (int) $record->get('total');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDependencyPaths(
        string $class,
        int $depth,
        bool $outbound,
        int $skip,
        int $limit,
    ): array {
        $relationship = $outbound
            ? '-[:DEPENDS_ON*1..%d]->'
            : '<-[:DEPENDS_ON*1..%d]-';

        $cypher = sprintf(
            <<<'CYPHER'
MATCH path = (c:Abstract {name: $class})%s(d:Abstract)
WITH d, min(length(path)) AS depth
ORDER BY depth ASC, d.name ASC
SKIP $skip LIMIT $limit
RETURN d.name AS name, labels(d) AS labels, d.kind AS kind, depth
CYPHER,
            sprintf($relationship, $depth),
        );

        $result = $this->connection->run($cypher, [
            'class' => $class,
            'skip' => $skip,
            'limit' => $limit,
        ]);

        return $this->mapDependencyRecords($result, 'DEPENDS_ON');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchUnresolvedDependencies(string $class): array
    {
        $result = $this->connection->run(
            <<<'CYPHER'
MATCH (c:Abstract {name: $class})-[:DEPENDS_ON]->(u:UnresolvedDependency:Abstract)
RETURN u.name AS name, u.reason AS reason
ORDER BY u.name ASC
CYPHER,
            ['class' => $class],
        );

        $entries = [];
        foreach ($result as $record) {
            $entries[] = [
                'name' => (string) $record->get('name'),
                'kind' => 'UnresolvedDependency',
                'relationship' => 'DEPENDS_ON',
                'reason' => (string) $record->get('reason'),
                'depth' => 1,
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapDependencyRecords(iterable $result, string $relationship): array
    {
        $entries = [];

        foreach ($result as $record) {
            $labels = $record->get('labels');
            $labelList = $labels instanceof CypherList
                ? array_values(iterator_to_array($labels))
                : (array) $labels;

            $entries[] = [
                'name' => (string) $record->get('name'),
                'kind' => $this->resolveNodeKind($labelList, $record->get('kind')),
                'relationship' => $relationship,
                'depth' => (int) $record->get('depth'),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function resolveNodeKind(array $labels, mixed $kind): string
    {
        if (in_array('UnresolvedDependency', $labels, true)) {
            return 'UnresolvedDependency';
        }

        if (in_array('Interface', $labels, true)) {
            return 'Interface';
        }

        if (in_array('AbstractType', $labels, true) && is_string($kind) && $kind !== '') {
            return $kind;
        }

        return 'Class';
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
}
