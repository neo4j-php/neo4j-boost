<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Observers\AttributedInvoiceObserver;

#[ObservedBy(AttributedInvoiceObserver::class)]
class AttributedInvoice extends Model
{
    //
}
