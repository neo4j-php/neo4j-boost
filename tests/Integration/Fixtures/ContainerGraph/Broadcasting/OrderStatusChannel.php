<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Broadcasting;

use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;

final class OrderStatusChannel
{
    public function __construct(private Logger $logger) {}

    public function join(object $user, string $orderId): bool
    {
        $this->logger->log('joining order '.$orderId);

        return true;
    }
}
