<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

final class StringViaNotification extends Notification
{
    public function via(object $notifiable): string
    {
        return 'mail';
    }
}
