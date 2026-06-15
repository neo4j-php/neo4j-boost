<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ResolutionCatalog;

final class CustomAccessorService
{
    public function generate(): string
    {
        return 'report';
    }
}
