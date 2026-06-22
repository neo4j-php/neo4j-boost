<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis\Support;

use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\TestCase;

final class TestbenchResolutionCatalogFactory
{
    private static ?ResolutionCatalog $catalog = null;

    public static function create(): ResolutionCatalog
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $case = new class('facade-collector-catalog-bootstrap') extends TestCase
        {
            public function bootstrapCatalog(): ResolutionCatalog
            {
                $this->setUp();

                $this->app->singleton(CustomAccessorService::class);

                return $this->app->make(ResolutionCatalog::class);
            }
        };

        self::$catalog = $case->bootstrapCatalog();

        return self::$catalog;
    }
}
