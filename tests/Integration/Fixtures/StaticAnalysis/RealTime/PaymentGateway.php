<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\RealTime;

final class PaymentGateway
{
    public function charge(): string
    {
        return 'charged';
    }
}
