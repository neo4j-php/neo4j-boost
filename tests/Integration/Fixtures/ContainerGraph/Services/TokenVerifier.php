<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services;

final class TokenVerifier
{
    public function verify(string $token): bool
    {
        return $token !== '';
    }
}
