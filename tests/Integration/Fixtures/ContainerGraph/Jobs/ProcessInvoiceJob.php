<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;

final class ProcessInvoiceJob implements ShouldQueue
{
    use Queueable;

    public function handle(Logger $logger): void
    {
        $logger->log('processed');
    }
}
