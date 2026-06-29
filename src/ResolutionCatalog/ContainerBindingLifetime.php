<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Contracts\Foundation\Application;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;

/**
 * Resolves BINDS_TO lifetime from the live Laravel container.
 */
final class ContainerBindingLifetime
{
    public function __construct(
        private Application $app,
    ) {}

    public function forAccessor(string $accessor): BindsToType
    {
        if (! $this->app->bound($accessor)) {
            return BindsToType::Normal;
        }

        return BindsToType::fromShared($this->app->isShared($accessor));
    }
}
