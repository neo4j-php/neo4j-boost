<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

abstract class Filter
{
    abstract public function apply(string $value): string;
}
