<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

enum ResolutionCatalogSource: string
{
    case LaravelFacade = 'laravel_facade';
    case AutoDiscoveredFacade = 'auto_discovered_facade';
}
