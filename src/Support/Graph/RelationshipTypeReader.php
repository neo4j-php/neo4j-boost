<?php

namespace Neo4j\LaravelBoost\Support\Graph;

/**
 * Resolves relationship type values from Neo4j with backward-compatible inference.
 */
final class RelationshipTypeReader
{
    /**
     * @return array{type: string, confidence?: string}
     */
    public static function dependsOn(mixed $storedType): array
    {
        if ($storedType === null || (string) $storedType === '') {
            return [
                'type' => DependsOnType::ConstructorInjection->value,
                'confidence' => 'inferred',
            ];
        }

        return [
            'type' => DependsOnType::assertAllowed((string) $storedType)->value,
        ];
    }

    /**
     * @return array{type: string, shared: bool, confidence?: string}
     */
    public static function bindsTo(mixed $storedType, mixed $legacyShared = null): array
    {
        if ($storedType === null || (string) $storedType === '') {
            $shared = filter_var($legacyShared, FILTER_VALIDATE_BOOLEAN);
            $type = BindsToType::fromShared($shared);

            return [
                'type' => $type->value,
                'shared' => $type === BindsToType::Singleton,
                'confidence' => 'inferred',
            ];
        }

        $type = BindsToType::assertAllowed((string) $storedType);

        return [
            'type' => $type->value,
            'shared' => $type === BindsToType::Singleton,
        ];
    }
}
