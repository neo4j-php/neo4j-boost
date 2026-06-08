<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

class Transistor
{
    public function __construct(
        protected PodcastParser $parser,
    ) {}
}
