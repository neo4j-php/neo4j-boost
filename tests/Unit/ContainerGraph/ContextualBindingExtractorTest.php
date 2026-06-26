<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Neo4j\LaravelBoost\ContainerGraph\ContextualBindingExtractor;
use Neo4j\LaravelBoost\ContainerGraph\ContextualGiveResolver;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PhotoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\NullFilter;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContextualBindingExtractorTest extends TestCase
{
    private ContextualBindingExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        ComplexContainerRegistry::register($this->app);
        $this->extractor = new ContextualBindingExtractor(new ContextualGiveResolver);
    }

    public function test_extracts_storage_disk_contextual_bindings(): void
    {
        $export = $this->extractor->extract($this->app);

        $this->assertContains(PhotoController::class, $export['when_classes']);
        $this->assertTrue(collect($export['rows'])->contains(
            static fn (array $row): bool => $row['when'] === PhotoController::class
                && $row['needs'] === Filesystem::class
                && $row['give'] === 'storage.disk:local',
        ));
    }

    public function test_resolve_marks_dynamic_closure_give_as_unresolved(): void
    {
        $resolver = new ContextualGiveResolver;
        $rows = $resolver->resolve(fn () => new \stdClass, 'App\\Contracts\\Example');

        $this->assertCount(1, $rows);
        $this->assertSame('closure@App\\Contracts\\Example', $rows[0]['name']);
        $this->assertSame('Closure', $rows[0]['kind']);
        $this->assertSame('dynamic_give_closure', $rows[0]['reason']);
    }

    public function test_resolve_class_name_give(): void
    {
        $resolver = new ContextualGiveResolver;
        $rows = $resolver->resolve(NullFilter::class, 'App\\Contracts\\Filter');

        $this->assertSame(NullFilter::class, $rows[0]['name']);
        $this->assertSame('Class', $rows[0]['kind']);
    }

    public function test_resolve_storage_disk_from_closure_source(): void
    {
        $resolver = new ContextualGiveResolver;
        $rows = $resolver->resolve(
            fn (): Filesystem => Storage::disk('s3'),
            Filesystem::class,
        );

        $this->assertSame('storage.disk:s3', $rows[0]['name']);
        $this->assertSame('Alias', $rows[0]['kind']);
    }
}
