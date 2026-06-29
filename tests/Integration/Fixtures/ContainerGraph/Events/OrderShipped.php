<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events;

final class OrderShipped
{
    public function __construct(public int $orderId) {}
}
