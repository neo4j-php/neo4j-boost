<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

final class CreateOrderNotification
{
    public function __construct(public int $orderId = 1) {}
}
