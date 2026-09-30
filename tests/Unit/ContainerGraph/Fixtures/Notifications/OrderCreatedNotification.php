<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OrderCreatedNotification extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Order created');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.order-created');
    }
}
