<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use Illuminate\Support\Facades\Cache;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog\CustomClassAccessorFacade;

final class InvoiceNotifier
{
    public function notifyWithCache(): void
    {
        Cache::put('invoice.sent', true);
    }

    public function notifyWithCustomFacade(): void
    {
        CustomClassAccessorFacade::handle();
    }

    public function skipDynamicFacade(string $method): void
    {
        Cache::$method('invoice.sent', true);
    }
}
