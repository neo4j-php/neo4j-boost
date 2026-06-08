<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;

class RedisEventPusher implements EventPusherInterface
{
    public function push(string $event): void
    {
        //
    }
}
