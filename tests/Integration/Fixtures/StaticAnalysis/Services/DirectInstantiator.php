<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

use DateTime;

final class DirectInstantiator
{
    public function createGateway(): PaymentGateway
    {
        return new PaymentGateway;
    }

    public function createLine(): InvoiceLineDto
    {
        return new InvoiceLineDto('Hosting', 9900);
    }

    public function createTimestamp(): DateTime
    {
        return new DateTime;
    }

    public function skipAnonymous(): object
    {
        return new class
        {
            public function label(): string
            {
                return 'anonymous';
            }
        };
    }

    public function skipDynamic(string $className): object
    {
        return new $className;
    }
}
