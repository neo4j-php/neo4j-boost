<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\StaticAnalysis\DependencyEdgeSource;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeConfidence;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeProvenance;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

/**
 * Resolves source, confidence, provenance, and remarks for dependency graph edges (SOFT-51).
 */
final class DependencyEdgeMetadataResolver
{
    /** @var list<string> */
    private const LITERAL_KEY_HELPERS = ['config', 'env'];

    /**
     * @param  array{type: string, source?: string, helper?: string, method?: string, parameter?: string}  $row
     * @return array{source: string, confidence: string, provenance: string, remarks: string}
     */
    public function forExtractedRow(array $row): array
    {
        $type = (string) ($row['type'] ?? '');
        $helper = (string) ($row['helper'] ?? '');

        return match ($type) {
            DependsOnType::ConstructorInjection->value => [
                'source' => DependencyEdgeSource::Reflection->value,
                'confidence' => DependencyEdgeConfidence::High->value,
                'provenance' => DependencyEdgeProvenance::Reflection->value,
                'remarks' => 'Type-hinted constructor parameter resolved via reflection.',
            ],
            DependsOnType::MethodInjection->value => [
                'source' => DependencyEdgeSource::Reflection->value,
                'confidence' => DependencyEdgeConfidence::High->value,
                'provenance' => DependencyEdgeProvenance::Heuristic->value,
                'remarks' => 'Method entry points are detected heuristically; parameter types come from reflection.',
            ],
            DependsOnType::ServiceLocation->value => [
                'source' => DependencyEdgeSource::Static->value,
                'confidence' => DependencyEdgeConfidence::High->value,
                'provenance' => DependencyEdgeProvenance::StaticScan->value,
                'remarks' => 'Literal class argument in app(), resolve(), or App::make() call.',
            ],
            DependsOnType::Facade->value => [
                'source' => DependencyEdgeSource::Static->value,
                'confidence' => DependencyEdgeConfidence::High->value,
                'provenance' => DependencyEdgeProvenance::StaticScan->value,
                'remarks' => 'Facade call resolved through the resolution catalog.',
            ],
            DependsOnType::GlobalHelper->value => $this->forGlobalHelperRow($helper),
            default => [
                'source' => (string) ($row['source'] ?? DependencyEdgeSource::Reflection->value),
                'confidence' => DependencyEdgeConfidence::Medium->value,
                'provenance' => DependencyEdgeProvenance::Reflection->value,
                'remarks' => '',
            ],
        };
    }

    /**
     * @param  array{type: string, reason?: string}  $row
     * @return array{source: string, confidence: string, provenance: string, remarks: string}
     */
    public function forUnresolvedRow(array $row): array
    {
        $reason = (string) ($row['reason'] ?? 'unresolved');

        return [
            'source' => DependencyEdgeSource::Reflection->value,
            'confidence' => DependencyEdgeConfidence::Low->value,
            'provenance' => DependencyEdgeProvenance::Reflection->value,
            'remarks' => 'Unresolved dependency ('.$reason.').',
        ];
    }

    /**
     * @param  array{source: string}  $row
     * @return array{source: string, confidence: string, provenance: string, remarks: string}
     */
    public function forFacadeCatalogRow(array $row): array
    {
        return [
            'source' => DependencyEdgeSource::Catalog->value,
            'confidence' => DependencyEdgeConfidence::High->value,
            'provenance' => DependencyEdgeProvenance::ResolutionCatalog->value,
            'remarks' => 'Facade catalog entry from '.$row['source'].'.',
        ];
    }

    /**
     * @return array{source: string, confidence: string, provenance: string, remarks: string}
     */
    public function forContainerBinding(): array
    {
        return [
            'source' => DependencyEdgeSource::Catalog->value,
            'confidence' => DependencyEdgeConfidence::High->value,
            'provenance' => DependencyEdgeProvenance::ContainerBinding->value,
            'remarks' => 'Registered Laravel container binding.',
        ];
    }

    /**
     * @return array{source: string, confidence: string, provenance: string, remarks: string}
     */
    private function forGlobalHelperRow(string $helper): array
    {
        if (in_array($helper, self::LITERAL_KEY_HELPERS, true)) {
            return [
                'source' => DependencyEdgeSource::Static->value,
                'confidence' => DependencyEdgeConfidence::Medium->value,
                'provenance' => DependencyEdgeProvenance::StaticScan->value,
                'remarks' => 'Literal config/env key only; runtime values are not resolved.',
            ];
        }

        return [
            'source' => DependencyEdgeSource::Static->value,
            'confidence' => DependencyEdgeConfidence::High->value,
            'provenance' => DependencyEdgeProvenance::StaticScan->value,
            'remarks' => 'Global helper resolved through the global helper catalog.',
        ];
    }
}
