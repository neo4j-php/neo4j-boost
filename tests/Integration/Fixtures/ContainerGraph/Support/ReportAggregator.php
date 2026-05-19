<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support;

class ReportAggregator
{
    /**
     * @param  iterable<int, object>  $reports
     */
    public function __construct(
        protected iterable $reports,
    ) {}
}
