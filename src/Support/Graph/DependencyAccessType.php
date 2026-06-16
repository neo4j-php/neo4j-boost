<?php

namespace Neo4j\LaravelBoost\Support\Graph;

use InvalidArgumentException;

enum DependencyAccessType: string
{
    case Di = 'di';
    case Facade = 'facade';
    case GlobalHelper = 'global_helper';
    case ServiceLocation = 'service_location';

    public static function assertAllowed(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("Unknown dependency access type: {$value}");
    }

    public static function fromDependsOnType(string $dependsOnType): self
    {
        return match ($dependsOnType) {
            DependsOnType::ConstructorInjection->value,
            DependsOnType::MethodInjection->value,
            DependsOnType::Instantiation->value => self::Di,
            DependsOnType::Facade->value => self::Facade,
            DependsOnType::GlobalHelper->value => self::GlobalHelper,
            DependsOnType::ServiceLocation->value => self::ServiceLocation,
            default => throw new InvalidArgumentException("Cannot map DEPENDS_ON type to access: {$dependsOnType}"),
        };
    }

    public function toDependsOnType(): DependsOnType
    {
        return match ($this) {
            self::Di => DependsOnType::ConstructorInjection,
            self::Facade => DependsOnType::Facade,
            self::GlobalHelper => DependsOnType::GlobalHelper,
            self::ServiceLocation => DependsOnType::ServiceLocation,
        };
    }
}
