<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

/**
 * Classifies where a class or closure is defined: `framework` (laravel/framework,
 * symfony/console), `vendor` (any other package), or `app`.
 */
final class CodeSource
{
    public static function forFile(string $file): string
    {
        $path = str_replace('\\', '/', $file);

        if (str_contains($path, '/vendor/laravel/framework/') || str_contains($path, '/vendor/symfony/console/')) {
            return 'framework';
        }

        return str_contains($path, '/vendor/') ? 'vendor' : 'app';
    }
}
