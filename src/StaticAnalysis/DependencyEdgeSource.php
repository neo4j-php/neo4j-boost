<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * Provenance for edges discovered by PHPStan static analysis (SOFT-43 POC).
 */
enum DependencyEdgeSource: string
{
    case Static = 'static';
}
