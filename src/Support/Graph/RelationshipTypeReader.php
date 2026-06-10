<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Resolves relationship type values from Neo4j with backward-compatible inference.
 */
final class RelationshipTypeReader
{
    /**
     * @return array{type: string, source: string, confidence: string, visibility: string}
     */
    public static function dependsOn(mixed $storedType, mixed $storedSource = null): array
    {
        return self::dependsOnMetadata($storedType, $storedSource);
    }

    /**
     * @return array{
     *     type: string,
     *     source: string,
     *     confidence: string,
     *     visibility: string
     * }
     */
    public static function dependsOnMetadata(mixed $storedType, mixed $storedSource = null): array
    {
        $typeMissing = $storedType === null || (string) $storedType === '';

        if ($typeMissing) {
            $type = DependsOnType::ConstructorInjection;
            $confidence = 'inferred';
        } else {
            $type = DependsOnType::assertAllowed((string) $storedType);
            $confidence = 'high';
        }

        return [
            'type' => $type->value,
            'source' => self::resolveDependsOnSource($storedSource, $typeMissing),
            'confidence' => $confidence,
            'visibility' => DependencyVisibility::fromDependsOnType($type)->value,
        ];
    }

    /**
     * @return array{type: string, shared: bool, source: string, confidence: string}
     */
    public static function bindsTo(mixed $storedType, mixed $legacyShared = null, mixed $storedSource = null): array
    {
        return self::bindsToMetadata($storedType, $legacyShared, $storedSource);
    }

    /**
     * @return array{
     *     type: string,
     *     shared: bool,
     *     source: string,
     *     confidence: string
     * }
     */
    public static function bindsToMetadata(mixed $storedType, mixed $legacyShared = null, mixed $storedSource = null): array
    {
        $typeMissing = $storedType === null || (string) $storedType === '';

        if ($typeMissing) {
            $shared = filter_var($legacyShared, FILTER_VALIDATE_BOOLEAN);
            $type = BindsToType::fromShared($shared);
            $confidence = 'inferred';
        } else {
            $type = BindsToType::assertAllowed((string) $storedType);
            $confidence = 'high';
        }

        return [
            'type' => $type->value,
            'shared' => $type === BindsToType::Singleton,
            'source' => self::resolveBindingSource($storedSource, $typeMissing),
            'confidence' => $confidence,
        ];
    }

    private static function resolveDependsOnSource(mixed $storedSource, bool $typeMissing): string
    {
        if ($storedSource !== null && (string) $storedSource !== '') {
            return DependencySource::assertAllowed((string) $storedSource)->value;
        }

        return DependencySource::StaticAnalysis->value;
    }

    private static function resolveBindingSource(mixed $storedSource, bool $typeMissing): string
    {
        if ($storedSource !== null && (string) $storedSource !== '') {
            return DependencySource::assertAllowed((string) $storedSource)->value;
        }

        return DependencySource::StaticAnalysis->value;
    }
}
