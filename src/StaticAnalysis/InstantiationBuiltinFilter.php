<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

/**
 * Guards which resolved `new ClassName()` targets become instantiation edges.
 *
 * Only empty/unresolvable class names are skipped here; anonymous (`new class {}`)
 * and dynamic (`new $var()`) instantiations are filtered upstream while parsing.
 * Internal/builtin classes are intentionally recorded — an application can bind
 * them into the container, so the detector reflects what the container can
 * actually resolve rather than assuming they are never managed. Opt-in skipping
 * of specific classes is handled separately as a configurable policy.
 */
final class InstantiationBuiltinFilter
{
    public function shouldSkip(string $className): bool
    {
        return $className === '';
    }
}
