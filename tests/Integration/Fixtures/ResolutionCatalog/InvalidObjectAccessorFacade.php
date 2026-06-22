<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;
use stdClass;

final class InvalidObjectAccessorFacade extends Facade
{
    protected static function getFacadeAccessor(): stdClass
    {
        return new stdClass;
    }
}
