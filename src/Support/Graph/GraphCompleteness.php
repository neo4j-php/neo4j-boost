<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Documents known limitations of the exported container dependency graph (SOFT-51).
 */
final class GraphCompleteness
{
    /**
     * @return array{status: string, limitations: list<string>}
     */
    public static function partial(): array
    {
        return [
            'status' => 'partial',
            'limitations' => [
                'Route and action-class resolution (e.g. container->make($controller)) is not modeled.',
                'Dynamic service location, facade, and helper calls without literal arguments are skipped.',
                'Static scan edges require NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS to be configured.',
                'Method injection entry points are detected via namespace and naming heuristics.',
                'Repeated direct instantiation of the same class may collapse to a single edge.',
                'config() and env() edges use literal keys only; confidence is medium.',
            ],
        ];
    }
}
