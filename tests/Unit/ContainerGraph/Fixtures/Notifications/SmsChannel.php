<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

final class SmsChannel
{
    public function send(object $notifiable, object $notification): void {}
}
