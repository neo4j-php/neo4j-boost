<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class NullFilter extends Filter
{
    public function apply(string $value): string
    {
        return $value;
    }
}
