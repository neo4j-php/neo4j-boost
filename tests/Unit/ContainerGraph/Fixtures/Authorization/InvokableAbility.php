<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Authorization;

final class InvokableAbility
{
    public function __invoke(mixed $user): bool
    {
        return true;
    }
}
