<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Listeners;

use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;

final class OrderShippedListener
{
    public function handle(OrderShipped $event, Logger $logger): void
    {
        $logger->log('order:'.$event->orderId);
    }
}
