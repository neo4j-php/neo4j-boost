<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Summarises how complete the exported dependency graph is for a class.
 */
final class GraphCompleteness
{
    /** @var list<string> */
    public const ACTIVE_DETECTORS = [
        DependsOnType::ConstructorInjection->value,
    ];

    /**
     * @return array{
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
