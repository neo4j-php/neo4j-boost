<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use InvalidArgumentException;

/**
 * High-level discovery source for dependency graph edges (SOFT-51).
 */
enum DependencyEdgeSource: string
{
    case Reflection = 'reflection';
    case Static = 'static';
    case Catalog = 'catalog';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown dependency edge source: {$value}");
    }
}
