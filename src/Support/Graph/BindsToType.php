<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum BindsToType: string
{
    case Normal = 'normal';
    case Singleton = 'singleton';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown BINDS_TO type: {$value}");
    }

    public static function fromShared(bool $shared): self
    {
        return $shared ? self::Singleton : self::Normal;
    }
}
