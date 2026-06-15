<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

enum ResolutionCatalogKind: string
{
    case Facade = 'facade';
    case GlobalHelper = 'global_helper';
}
