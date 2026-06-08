<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class ScopedTransistor
{
    public function __construct(
        protected PodcastParser $parser,
    ) {}
}
