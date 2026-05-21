<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class DecoratedService
{
    public function __construct(
        public DecoratableService $inner,
    ) {}
}
