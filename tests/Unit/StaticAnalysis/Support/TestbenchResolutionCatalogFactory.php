<?php

namespace Neo4j\LaravelBoost\Tests\Unit\StaticAnalysis\Support;

use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomAccessorService;
use Neo4j\LaravelBoost\Tests\TestCase;

final class TestbenchResolutionCatalogFactory
{
    private static ?ResolutionCatalog $catalog = null;

    private static ?TestCase $bootstrapCase = null;

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

            public function shutdown(): void
            {
                $this->tearDown();
            }
        };

        self::$bootstrapCase = $case;
        self::$catalog = $case->bootstrapCatalog();

        return self::$catalog;
    }

    public static function reset(): void
    {
        if (self::$bootstrapCase === null) {
            return;
        }

        if (method_exists(self::$bootstrapCase, 'shutdown')) {
            self::$bootstrapCase->shutdown();
        }

        self::$bootstrapCase = null;
        self::$catalog = null;
    }
}
