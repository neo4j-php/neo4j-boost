<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

final class ConstructorHeavyNotification extends Notification
{
    public function __construct(public object $invoice) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['broadcast'];
    }
}
