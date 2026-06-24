<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use ReflectionClass;

/**
 * Skips PHP internal / builtin classes that should not be treated as container bypasses.
 */
final class InstantiationBuiltinFilter
{
    public function shouldSkip(string $className): bool
    {
        if ($className === '') {
            return true;
        }

        if (! class_exists($className)) {
            return false;
        }

        try {
            return (new ReflectionClass($className))->isInternal();
        } catch (\Throwable) {
            return false;
        }
    }
}
