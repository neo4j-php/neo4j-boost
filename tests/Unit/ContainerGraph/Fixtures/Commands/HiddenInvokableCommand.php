<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Commands;

use Illuminate\Console\Command;

final class HiddenInvokableCommand extends Command
{
    protected $signature = 'internal:warm';

    protected $hidden = true;

    public function __invoke(): int
    {
        return self::SUCCESS;
    }
}
