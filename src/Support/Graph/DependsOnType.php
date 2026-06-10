<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum DependsOnType: string
{
    case ConstructorInjection = 'constructor_injection';
    case MethodInjection = 'method_injection';
    case Facade = 'facade';
    case GlobalHelper = 'global_helper';
    case ServiceLocation = 'service_location';
    case Instantiation = 'instantiation';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown DEPENDS_ON type: {$value}");
    }
}
