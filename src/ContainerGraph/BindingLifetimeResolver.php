<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\Support\Graph\ResolvesToLifetime;

/**
 * Resolves RESOLVES_TO lifetime from Laravel container binding metadata.
 */
final class BindingLifetimeResolver
{
    /**
     * @param  array<string, array{concrete: mixed, shared: bool}>  $bindings
     */
    public function forIdentifier(string $identifier, array $bindings): ResolvesToLifetime
    {
        if (isset($bindings[$identifier])) {
            return ResolvesToLifetime::fromShared((bool) ($bindings[$identifier]['shared'] ?? false));
        }

        return ResolvesToLifetime::Bind;
    }
}
