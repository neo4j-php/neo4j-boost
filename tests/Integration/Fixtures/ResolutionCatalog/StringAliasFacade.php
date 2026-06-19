<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;

final class StringAliasFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'app.legacy';
    }
}
