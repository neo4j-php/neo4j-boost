<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class DecoratableService
{
    public function __construct(
        public string $label,
    ) {}
}
