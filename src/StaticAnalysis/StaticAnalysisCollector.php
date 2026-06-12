<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * In-memory store for edges collected during a PHPStan run (SOFT-43 POC).
 */
final class StaticAnalysisCollector
{
    /** @var list<ServiceLocationEdge> */
    private static array $serviceLocationEdges = [];

    public static function addServiceLocation(ServiceLocationEdge $edge): void
    {
        self::$serviceLocationEdges[] = $edge;
    }

    /**
     * @return list<ServiceLocationEdge>
     */
    public static function serviceLocationEdges(): array
    {
        return self::$serviceLocationEdges;
    }

    public static function reset(): void
    {
        self::$serviceLocationEdges = [];
    }
}
