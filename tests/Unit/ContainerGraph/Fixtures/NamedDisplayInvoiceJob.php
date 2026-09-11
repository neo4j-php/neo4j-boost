<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class NamedDisplayInvoiceJob implements ShouldQueue
{
    use Queueable;

    public function displayName(): string
    {
        return 'Custom invoice run';
    }

    public function handle(): void
    {
        //
    }
}
