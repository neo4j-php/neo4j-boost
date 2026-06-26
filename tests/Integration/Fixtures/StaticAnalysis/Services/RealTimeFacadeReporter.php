<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use Facades\Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\RealTime\PaymentGateway;

final class RealTimeFacadeReporter
{
    public function report(): string
    {
        return PaymentGateway::charge();
    }
}
