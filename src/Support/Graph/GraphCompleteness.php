<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Documents known limitations and summarises how complete the exported
 * container dependency graph is for a class.
 */
final class GraphCompleteness
{
    /** @var list<string> */
    public const ACTIVE_DETECTORS = [
        DependsOnType::ConstructorInjection->value,
    ];

    /**
     * @return list<string>
     */
    public static function limitations(): array
    {
        return [
            'Route and action-class resolution (e.g. container->make($controller)) is not modeled.',
            'Dynamic service location, facade, and helper calls without literal arguments are skipped.',
            'Static scan edges require NEO4J_CONTAINER_GRAPH_STATIC_SCAN_PATHS to be configured.',
            'Method injection entry points are detected via namespace and naming heuristics.',
            'Repeated direct instantiation of the same class may collapse to a single edge.',
            'config() and env() edges use literal keys only; confidence is medium.',
        ];
    }

    /**
     * @return array{status: string, limitations: list<string>}
     */
    public static function partial(): array
    {
        return [
            'status' => 'partial',
            'limitations' => self::limitations(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     limitations: list<string>,
     *     declared_count: int,
     *     hidden_count: int,
     *     total_count: int,
     *     coverage: string,
     *     detectors_active: list<string>,
     *     detectors_pending: list<string>
     * }
     */
    public static function build(int $declaredCount, int $hiddenCount): array
    {
        $total = $declaredCount + $hiddenCount;

        return [
            'status' => 'partial',
            'limitations' => self::limitations(),
            'declared_count' => $declaredCount,
            'hidden_count' => $hiddenCount,
            'total_count' => $total,
            'coverage' => self::resolveCoverage($declaredCount, $hiddenCount, $total),
            'detectors_active' => self::ACTIVE_DETECTORS,
            'detectors_pending' => self::pendingDetectors(),
        ];
    }

    /**
     * @return array{
     *     status: string,
     *     limitations: list<string>,
     *     declared_count: int,
     *     hidden_count: int,
     *     total_count: int,
     *     coverage: string,
     *     detectors_active: list<string>,
     *     detectors_pending: list<string>
     * }
     */
    public static function empty(): array
    {
        return self::build(0, 0);
    }

    /**
     * @return array{
     *     status: string,
     *     limitations: list<string>,
     *     declared_count: int,
     *     hidden_count: int,
     *     total_count: int,
     *     coverage: string,
     *     detectors_active: list<string>,
     *     detectors_pending: list<string>
     * }
     */
    public static function unknown(): array
    {
        $block = self::empty();
        $block['coverage'] = 'unknown';

        return $block;
    }

    /**
     * @return list<string>
     */
    private static function pendingDetectors(): array
    {
        $pending = [];

        foreach (DependsOnType::cases() as $type) {
            if ($type === DependsOnType::ConstructorInjection) {
                continue;
            }

            $pending[] = $type->value;
        }

        return $pending;
    }

    private static function resolveCoverage(int $declaredCount, int $hiddenCount, int $total): string
    {
        if ($total === 0) {
            return 'empty';
        }

        if ($hiddenCount > 0 && $declaredCount > 0) {
            return 'mixed';
        }

        if ($hiddenCount > 0) {
            return 'hidden_only';
        }

        return self::pendingDetectors() === [] ? 'complete' : 'partial';
    }
}
