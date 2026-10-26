<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Events\InvoiceCreated;

class Invoice extends Model
{
    protected $dispatchesEvents = [
        'created' => InvoiceCreated::class,
    ];
}
