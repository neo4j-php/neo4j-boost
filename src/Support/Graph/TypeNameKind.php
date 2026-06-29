<?php

namespace Neo4j\LaravelBoost\Support\Graph;

final class TypeNameKind
{
    public static function for(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }
}
