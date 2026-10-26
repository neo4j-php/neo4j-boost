<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Observers;

use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models\Invoice;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        //
    }

    public function deleted(Invoice $invoice): void
    {
        //
    }
}
