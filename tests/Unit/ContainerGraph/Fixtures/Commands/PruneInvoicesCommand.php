<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Commands;

use Illuminate\Console\Command;

final class PruneInvoicesCommand extends Command
{
    protected $signature = 'invoices:prune';

    protected $description = 'Prune stale invoices';

    protected $aliases = ['invoices:clean', 'prune-invoices'];

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
