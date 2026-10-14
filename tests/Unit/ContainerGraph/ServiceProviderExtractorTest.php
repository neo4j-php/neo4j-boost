<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Cache\CacheServiceProvider;
use Neo4j\LaravelBoost\ContainerGraph\ServiceProviderExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers\BillingServiceProvider;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers\ExtendedBillingServiceProvider;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers\Ledger;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Providers\ReportsServiceProvider;

class ServiceProviderExtractorTest extends TestCase
{
    public function test_attributes_property_and_register_bindings_to_a_loaded_provider(): void
    {
        $extracted = (new ServiceProviderExtractor)->extract(
            [BillingServiceProvider::class => true],
            [],
            static fn (string $abstract): bool => $abstract !== 'billing.unbound',
        );

        $this->assertSame([[
            'key' => BillingServiceProvider::class,
            'name' => 'BillingServiceProvider',
            'deferred' => false,
            'loaded' => true,
            'source' => 'app',
        ]], $extracted['providers']);

        $this->assertSame([
            ['billing.gateway', 'Alias', 'normal', 'bindings'],
            [Ledger::class, 'Interface', 'singleton', 'singletons'],
            ['billing.clock', 'Alias', 'singleton', 'singletons'],
            ['billing.session', 'Alias', 'scoped', 'register'],
            ['billing.config', 'Alias', 'instance', 'register'],
            ['billing.reports', 'Alias', 'singleton', 'register'],
        ], array_map(static fn (array $row): array => [$row['abstract'], $row['abstract_kind'], $row['type'], $row['via']], $extracted['registers']));
        $this->assertSame([BillingServiceProvider::class], array_values(array_unique(array_column($extracted['registers'], 'provider_key'))));
    }

    public function test_unloaded_deferred_provider_exports_provided_services(): void
    {
        $extracted = (new ServiceProviderExtractor)->extract(
            [],
            ['reports.exporter' => ReportsServiceProvider::class, 'reports.cache' => ReportsServiceProvider::class],
            static fn (string $abstract): bool => $abstract === 'reports.exporter',
        );

        $this->assertSame(ReportsServiceProvider::class, $extracted['providers'][0]['key']);
        $this->assertTrue($extracted['providers'][0]['deferred']);
        $this->assertFalse($extracted['providers'][0]['loaded']);
        $this->assertSame([
            ['reports.exporter', 'singleton', 'register'],
            ['reports.cache', '', 'provides'],
        ], array_map(static fn (array $row): array => [$row['abstract'], $row['type'], $row['via']], $extracted['registers']));
    }

    public function test_inherited_register_is_attributed_to_the_registered_subclass(): void
    {
        $extracted = (new ServiceProviderExtractor)->extract(
            [ExtendedBillingServiceProvider::class => true],
            [],
            static fn (): bool => true,
        );

        $registers = array_column($extracted['registers'], 'via', 'abstract');
        $this->assertSame('register', $registers['billing.unbound']);
        $this->assertSame('register', $registers['billing.session']);
        $this->assertSame([ExtendedBillingServiceProvider::class], array_values(array_unique(array_column($extracted['registers'], 'provider_key'))));
    }

    public function test_framework_provider_source_and_non_providers_are_skipped(): void
    {
        $extracted = (new ServiceProviderExtractor)->extract(
            [\stdClass::class => true, 'App\\Providers\\Missing' => true],
            ['cache' => CacheServiceProvider::class],
            static fn (): bool => true,
        );

        $this->assertCount(1, $extracted['providers']);
        $this->assertSame('framework', $extracted['providers'][0]['source']);
        $this->assertTrue($extracted['providers'][0]['deferred']);
        $this->assertContains('cache', array_column($extracted['registers'], 'abstract'));
    }

    public function test_reads_the_live_application_by_default(): void
    {
        $this->app->register(BillingServiceProvider::class);

        $extracted = (new ServiceProviderExtractor)->extract();

        $keys = array_column($extracted['providers'], 'key');
        $this->assertContains(BillingServiceProvider::class, $keys);
        $this->assertContains(CacheServiceProvider::class, $keys);
        $billing = array_column(array_filter(
            $extracted['registers'],
            static fn (array $row): bool => $row['provider_key'] === BillingServiceProvider::class,
        ), 'via', 'abstract');
        $this->assertTrue($this->app->bound('billing.dynamic'));
        $this->assertArrayNotHasKey('billing.dynamic', $billing);
        $this->assertSame('register', $billing['billing.unbound']);
    }
}
