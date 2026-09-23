<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

use Illuminate\Notifications\Notification;

final class TypedNotifiableNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(TypedNotifiable $notifiable): array
    {
        return ['mail', 'database'];
    }
}
