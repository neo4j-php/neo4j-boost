<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Illuminate\Support\Facades\App;

final class AppFacadeClassChecker
{
    public static function is(string $className): bool
    {
        return is_a(ltrim($className, '\\'), App::class, true);
    }
}
