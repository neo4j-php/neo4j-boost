<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

final class BillingReportsService
{
    public function generate(): string
    {
        return 'report';
    }
}
