<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\BindingLifetimeResolver;
use Neo4j\LaravelBoost\ContainerGraph\DependencyChainBuilder;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use PHPUnit\Framework\TestCase;

class DependencyChainBuilderTest extends TestCase
{
    private DependencyChainBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DependencyChainBuilder(new BindingLifetimeResolver);
    }

    public function test_legacy_dependency_row_maps_to_three_node_chain(): void
    {
        $chain = $this->builder->fromLegacyDependencyRow([
            'class' => 'App\\Services\\Foo',
            'dependency' => 'App\\Contracts\\Bar',
            'dependencyKind' => 'Interface',
            'type' => DependsOnType::ConstructorInjection->value,
            'via' => '',
            'file' => '',
            'line' => 0,
        ], []);

        $this->assertSame('App\\Services\\Foo', $chain['instance']);
        $this->assertSame('di|App\\Contracts\\Bar', $chain['dependency_key']);
        $this->assertSame('di', $chain['access']);
        $this->assertSame('App\\Contracts\\Bar', $chain['identifier']);
        $this->assertSame('Interface', $chain['identifier_kind']);
        $this->assertSame('bind', $chain['lifetime']);
    }

    public function test_service_location_row_preserves_file_line_and_via(): void
    {
        $chain = $this->builder->fromLegacyDependencyRow([
            'class' => 'App\\Services\\Foo',
            'dependency' => 'App\\Services\\Bar',
            'dependencyKind' => 'Class',
            'type' => DependsOnType::ServiceLocation->value,
            'via' => 'app',
            'file' => 'app/Services/Foo.php',
            'line' => 42,
        ], []);

        $this->assertSame('service_location', $chain['access']);
        $this->assertSame('app', $chain['via']);
        $this->assertSame('app/Services/Foo.php', $chain['file']);
        $this->assertSame(42, $chain['line']);
    }

    public function test_facade_export_row_creates_catalog_only_chain(): void
    {
        $chain = $this->builder->fromFacadeExportRow([
            'facade_class' => 'Illuminate\\Support\\Facades\\Cache',
            'abstract' => 'Illuminate\\Contracts\\Cache\\Repository',
            'abstractKind' => 'Interface',
            'binding_key' => 'cache',
            'source' => 'laravel_facade',
            'binds_to_type' => 'singleton',
        ]);

        $this->assertSame('', $chain['instance']);
        $this->assertSame('facade|cache', $chain['dependency_key']);
        $this->assertSame('facade', $chain['access']);
        $this->assertSame('cache', $chain['identifier']);
        $this->assertSame('singleton', $chain['lifetime']);
        $this->assertSame('Illuminate\\Support\\Facades\\Cache', $chain['via']);
    }

    public function test_unresolved_row_sets_identifier_kind_and_reason(): void
    {
        $chain = $this->builder->fromUnresolvedRow([
            'class' => 'App\\Services\\Foo',
            'name' => 'missing',
            'reason' => 'no_type_hint',
            'type' => DependsOnType::ConstructorInjection->value,
        ], []);

        $this->assertSame('Unresolved', $chain['identifier_kind']);
        $this->assertSame('no_type_hint', $chain['reason']);
        $this->assertSame('unresolved', $chain['via']);
    }
}
