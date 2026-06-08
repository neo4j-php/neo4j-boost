<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class ProfanityFilter extends Filter
{
    public function apply(string $value): string
    {
        return $value;
    }
}
