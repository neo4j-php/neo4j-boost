<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class QueuedWelcomeMailable extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public $mailer = 'ses';

    public function build(): self
    {
        return $this->subject('Welcome')->view('mail.welcome');
    }
}
