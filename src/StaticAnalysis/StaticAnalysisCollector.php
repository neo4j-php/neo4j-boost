<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * In-memory store for edges collected during a PHPStan run (SOFT-43 POC).
 */
final class StaticAnalysisCollector
{
    /** @var list<ServiceLocationEdge> */
    private static array $serviceLocationEdges = [];

    /** @var list<FacadeEdge> */
    private static array $facadeEdges = [];

    public static function addServiceLocation(ServiceLocationEdge $edge): void
    {
        self::$serviceLocationEdges[] = $edge;
    }

    public static function addFacade(FacadeEdge $edge): void
    {
        self::$facadeEdges[] = $edge;
    }

    /**
     * @return list<ServiceLocationEdge>
     */
    public static function serviceLocationEdges(): array
    {
        return self::$serviceLocationEdges;
    }

    /**
     * @return list<FacadeEdge>
     */
    public static function facadeEdges(): array
    {
        return self::$facadeEdges;
    }

    public static function reset(): void
    {
        self::$serviceLocationEdges = [];
        self::$facadeEdges = [];
    }
}
