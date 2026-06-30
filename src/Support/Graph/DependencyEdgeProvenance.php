<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum DependencyEdgeProvenance: string
{
    case Reflection = 'reflection';
    case StaticScan = 'static_scan';
    case ResolutionCatalog = 'resolution_catalog';
    case Heuristic = 'heuristic';
    case ContainerBinding = 'container_binding';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown dependency edge provenance: {$value}");
    }
}
