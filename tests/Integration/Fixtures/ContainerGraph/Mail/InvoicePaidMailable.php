<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;

final class InvoicePaidMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function build(Logger $logger): self
    {
        $logger->log('building invoice mail');

        return $this->subject('Invoice paid')->view('mail.invoice-paid');
    }
}
