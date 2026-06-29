<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Commands;

use Illuminate\Console\Command;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\ReportAggregator;

final class SyncReportsCommand extends Command
{
    protected $signature = 'reports:sync';

    protected $description = 'Sync report aggregates';

    public function handle(ReportAggregator $aggregator): int
    {
        return self::SUCCESS;
    }
}
