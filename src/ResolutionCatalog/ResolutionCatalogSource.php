<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

enum ResolutionCatalogSource: string
{
    case LaravelFacade = 'laravel_facade';
    case CustomFacade = 'custom_facade';
}
