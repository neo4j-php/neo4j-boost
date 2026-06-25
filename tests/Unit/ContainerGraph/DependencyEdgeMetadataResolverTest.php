<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\DependencyEdgeMetadataResolver;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeConfidence;
use Neo4j\LaravelBoost\Support\Graph\DependencyEdgeProvenance;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use PHPUnit\Framework\TestCase;

class DependencyEdgeMetadataResolverTest extends TestCase
{
    private DependencyEdgeMetadataResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyEdgeMetadataResolver;
    }

    public function test_constructor_injection_is_high_confidence_reflection(): void
    {
        $metadata = $this->resolver->forExtractedRow([
            'type' => DependsOnType::ConstructorInjection->value,
        ]);

        $this->assertSame('reflection', $metadata['source']);
        $this->assertSame(DependencyEdgeConfidence::High->value, $metadata['confidence']);
        $this->assertSame(DependencyEdgeProvenance::Reflection->value, $metadata['provenance']);
    }

    public function test_method_injection_uses_heuristic_provenance(): void
    {
        $metadata = $this->resolver->forExtractedRow([
            'type' => DependsOnType::MethodInjection->value,
            'method' => 'store',
            'parameter' => 'request',
        ]);

        $this->assertSame(DependencyEdgeConfidence::High->value, $metadata['confidence']);
        $this->assertSame(DependencyEdgeProvenance::Heuristic->value, $metadata['provenance']);
    }

    public function test_service_location_static_scan_is_high_confidence(): void
    {
        $metadata = $this->resolver->forExtractedRow([
            'type' => DependsOnType::ServiceLocation->value,
        ]);

        $this->assertSame('static', $metadata['source']);
        $this->assertSame(DependencyEdgeConfidence::High->value, $metadata['confidence']);
        $this->assertSame(DependencyEdgeProvenance::StaticScan->value, $metadata['provenance']);
    }

    public function test_config_helper_is_medium_confidence(): void
    {
        $metadata = $this->resolver->forExtractedRow([
            'type' => DependsOnType::GlobalHelper->value,
            'helper' => 'config',
        ]);

        $this->assertSame(DependencyEdgeConfidence::Medium->value, $metadata['confidence']);
        $this->assertStringContainsString('literal', strtolower($metadata['remarks']));
    }

    public function test_cache_helper_is_high_confidence(): void
    {
        $metadata = $this->resolver->forExtractedRow([
            'type' => DependsOnType::GlobalHelper->value,
            'helper' => 'cache',
        ]);

        $this->assertSame(DependencyEdgeConfidence::High->value, $metadata['confidence']);
    }

    public function test_unresolved_row_is_low_confidence(): void
    {
        $metadata = $this->resolver->forUnresolvedRow([
            'type' => DependsOnType::ConstructorInjection->value,
            'reason' => 'no_type_hint',
        ]);

        $this->assertSame(DependencyEdgeConfidence::Low->value, $metadata['confidence']);
        $this->assertStringContainsString('no_type_hint', $metadata['remarks']);
    }

    public function test_facade_catalog_row_uses_resolution_catalog_provenance(): void
    {
        $metadata = $this->resolver->forFacadeCatalogRow([
            'source' => 'laravel_facade',
        ]);

        $this->assertSame('catalog', $metadata['source']);
        $this->assertSame(DependencyEdgeProvenance::ResolutionCatalog->value, $metadata['provenance']);
    }

    public function test_container_binding_metadata(): void
    {
        $metadata = $this->resolver->forContainerBinding();

        $this->assertSame(DependencyEdgeProvenance::ContainerBinding->value, $metadata['provenance']);
        $this->assertSame(DependencyEdgeConfidence::High->value, $metadata['confidence']);
    }
}
