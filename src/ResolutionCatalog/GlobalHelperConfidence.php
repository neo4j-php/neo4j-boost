<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

enum GlobalHelperConfidence: string
{
    case High = 'high';
    case Low = 'low';
}
