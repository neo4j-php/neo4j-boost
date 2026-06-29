<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Resolves BINDS_TO relationship type values from Neo4j.
 */
final class RelationshipTypeReader
{
    /**
     * @return array{type: string, shared: bool}
     */
    public static function bindsTo(mixed $storedType): array
    {
        $type = BindsToType::assertAllowed((string) $storedType);

        return [
            'type' => $type->value,
            'shared' => $type === BindsToType::Singleton,
        ];
    }
}
