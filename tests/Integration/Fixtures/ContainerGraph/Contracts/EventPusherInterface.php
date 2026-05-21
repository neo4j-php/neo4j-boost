<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts;

interface EventPusherInterface
{
    public function push(string $event): void;
}
