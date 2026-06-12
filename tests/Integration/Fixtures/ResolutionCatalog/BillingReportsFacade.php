<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

use Illuminate\Support\Facades\Facade;

final class BillingReportsFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingReportsService::class;
    }
}
