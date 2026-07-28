<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum ResolvesToLifetime: string
{
    case Singleton = 'singleton';
    case Bind = 'bind';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown RESOLVES_TO lifetime: {$value}");
    }

    public static function fromShared(bool $shared): self
    {
        return $shared ? self::Singleton : self::Bind;
    }

    public static function fromBindsToType(BindsToType $type): self
    {
        return match ($type) {
            BindsToType::Singleton => self::Singleton,
            BindsToType::Normal => self::Bind,
        };
    }
}
