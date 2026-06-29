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

    public function test_extracted_dependency_row_maps_to_three_node_chain(): void
    {
        $chain = $this->builder->fromExtractedDependencyRow([
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
        $this->assertSame(DependsOnType::ConstructorInjection->value, $chain['injection_type']);
    }

    public function test_method_injection_row_uses_unique_dependency_key_and_metadata(): void
    {
        $chain = $this->builder->fromExtractedDependencyRow([
            'class' => 'App\\Http\\Controllers\\PostController',
            'dependency' => 'App\\Http\\Requests\\StorePostRequest',
            'dependencyKind' => 'Class',
            'type' => DependsOnType::MethodInjection->value,
            'method' => 'store',
            'parameter' => 'request',
            'via' => '',
            'file' => 'app/Http/Controllers/PostController.php',
            'line' => 18,
        ], []);

        $this->assertSame('di|App\\Http\\Requests\\StorePostRequest|store|request', $chain['dependency_key']);
        $this->assertSame(DependsOnType::MethodInjection->value, $chain['injection_type']);
        $this->assertSame('store', $chain['method']);
        $this->assertSame('request', $chain['parameter']);
        $this->assertSame('app/Http/Controllers/PostController.php', $chain['file']);
        $this->assertSame(18, $chain['line']);
    }

    public function test_service_location_row_preserves_file_line_and_via(): void
    {
        $chain = $this->builder->fromExtractedDependencyRow([
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

    public function test_global_helper_row_sets_helper_and_dependency_key(): void
    {
        $chain = $this->builder->fromExtractedDependencyRow([
            'class' => 'App\\Services\\Worker',
            'dependency' => 'cache',
            'dependencyKind' => 'Alias',
            'type' => DependsOnType::GlobalHelper->value,
            'helper' => 'cache',
            'via' => 'cache',
            'file' => 'app/Services/Worker.php',
            'line' => 10,
        ], []);

        $this->assertSame('global_helper', $chain['access']);
        $this->assertSame('global_helper|cache|cache', $chain['dependency_key']);
        $this->assertSame(DependsOnType::GlobalHelper->value, $chain['injection_type']);
        $this->assertSame('cache', $chain['helper']);
        $this->assertSame('cache', $chain['via']);
    }

    public function test_instantiation_row_sets_injection_type_and_via(): void
    {
        $chain = $this->builder->fromExtractedDependencyRow([
            'class' => 'App\\Services\\CheckoutService',
            'dependency' => 'App\\Services\\PaymentGateway',
            'dependencyKind' => 'Class',
            'type' => DependsOnType::Instantiation->value,
            'via' => 'new App\\Services\\PaymentGateway',
            'file' => 'app/Services/CheckoutService.php',
            'line' => 12,
        ], []);

        $this->assertSame('di', $chain['access']);
        $this->assertSame('di|App\\Services\\PaymentGateway', $chain['dependency_key']);
        $this->assertSame(DependsOnType::Instantiation->value, $chain['injection_type']);
        $this->assertSame('new App\\Services\\PaymentGateway', $chain['via']);
        $this->assertSame(12, $chain['line']);
    }
}
