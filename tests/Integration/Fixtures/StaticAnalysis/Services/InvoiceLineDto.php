<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\StaticAnalysis\Services;

final class InvoiceLineDto
{
    public function __construct(
        public string $description,
        public int $amountCents,
    ) {}
}
