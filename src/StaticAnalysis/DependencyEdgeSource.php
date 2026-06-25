<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * High-level discovery source for dependency graph edges (SOFT-51).
 */
enum DependencyEdgeSource: string
{
    case Reflection = 'reflection';
    case Static = 'static';
    case Catalog = 'catalog';
}
