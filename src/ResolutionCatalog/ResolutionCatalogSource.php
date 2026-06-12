<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

enum ResolutionCatalogSource: string
{
    case LaravelFacade = 'laravel_facade';
    case GlobalHelper = 'global_helper';
    case CustomFacade = 'custom_facade';
}
