<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum DependencySource: string
{
    case StaticAnalysis = 'static_analysis';
    case User = 'user';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown dependency source: {$value}");
    }
}
