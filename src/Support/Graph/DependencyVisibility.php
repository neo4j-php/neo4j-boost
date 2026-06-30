<?php

namespace Neo4j\LaravelBoost\Support\Graph;

enum DependencyVisibility: string
{
    case Declared = 'declared';
    case Hidden = 'hidden';

    public static function fromDependsOnType(DependsOnType $type): self
    {
        return $type === DependsOnType::ConstructorInjection
            ? self::Declared
            : self::Hidden;
    }
}
