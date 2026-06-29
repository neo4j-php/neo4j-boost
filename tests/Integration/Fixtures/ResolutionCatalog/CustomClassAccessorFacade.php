<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string handle()
 */
final class CustomClassAccessorFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CustomAccessorService::class;
    }
}
