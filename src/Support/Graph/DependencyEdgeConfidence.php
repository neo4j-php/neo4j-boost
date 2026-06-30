<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum DependencyEdgeConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown dependency edge confidence: {$value}");
    }
}
