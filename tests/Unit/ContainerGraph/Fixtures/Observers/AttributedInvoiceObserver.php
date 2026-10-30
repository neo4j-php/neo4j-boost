<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Observers;

use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models\AttributedInvoice;

class AttributedInvoiceObserver
{
    public function saved(AttributedInvoice $invoice): void
    {
        //
    }
}
