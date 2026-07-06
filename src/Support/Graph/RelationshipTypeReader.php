<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Resolves BINDS_TO and DEPENDS_ON relationship type values from Neo4j.
 */
final class RelationshipTypeReader
{
    /**
     * @return array{type: string, source: string, confidence: string, visibility: string}
     */
    public static function dependsOn(mixed $storedType, mixed $storedSource = null): array
    {
        $type = DependsOnType::assertAllowed((string) $storedType);

        return [
            'type' => $type->value,
            'source' => self::resolveSource($storedSource),
            'confidence' => 'high',
            'visibility' => DependencyVisibility::fromDependsOnType($type)->value,
        ];
    }

    /**
     * @return array{type: string, shared: bool, source: string, confidence: string}
     */
    public static function bindsTo(mixed $storedType, mixed $storedSource = null): array
    {
        $type = BindsToType::assertAllowed((string) $storedType);

        return [
            'type' => $type->value,
            'shared' => $type === BindsToType::Singleton,
            'source' => self::resolveSource($storedSource),
            'confidence' => 'high',
        ];
    }

    private static function resolveSource(mixed $storedSource): string
    {
        if ($storedSource !== null && (string) $storedSource !== '') {
            return DependencySource::assertAllowed((string) $storedSource)->value;
        }

        return DependencySource::StaticAnalysis->value;
    }
}
