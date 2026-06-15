<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\PhotoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Controllers\VideoController;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\DecoratableService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\DecoratedService;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Filter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Firewall;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\NullFilter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\ProfanityFilter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\RedisEventPusher;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\ScopedTransistor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Transistor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\CpuReport;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\MemoryReport;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Support\ReportAggregator;

/**
 * Registers a broad set of Laravel container bindings for integration testing.
 *
 * Mirrors patterns from https://laravel.com/docs/13.x/container
 */
final class ComplexContainerRegistry
{
    public const BINDING_KEYS = [
        'interface_to_class',
        'string_alias',
        'primitive_alias',
        'closure_binding',
        'singleton_binding',
        'scoped_binding',
        'instance_binding',
        'bind_if_binding',
        'tagged_aggregator',
        'extended_service',
    ];

    public static function register(Application $app): void
    {
        $app->bind(Transistor::class, function (Application $application): Transistor {
            return new Transistor($application->make(PodcastParser::class));
        });

        $app->bind(Firewall::class, Firewall::class);

        $app->bindIf('legacy.podcast.parser', PodcastParser::class);

        $app->singleton(RedisEventPusher::class, fn (): RedisEventPusher => new RedisEventPusher);

        $app->scoped(ScopedTransistor::class, function (Application $application): ScopedTransistor {
            return new ScopedTransistor($application->make(PodcastParser::class));
        });

        $app->instance('podcast.parser.instance', new PodcastParser);

        $app->bind(EventPusherInterface::class, RedisEventPusher::class);

        $app->alias(RedisEventPusher::class, 'event.pusher');

        $app->bind('app.currency', 'USD');

        $app->bind('reports.analyzer', fn (Application $application): ReportAggregator => $application->make(ReportAggregator::class));

        $app->when(PhotoController::class)
            ->needs(Filesystem::class)
            ->give(fn (): Filesystem => Storage::disk('local'));

        $app->when(VideoController::class)
            ->needs(Filesystem::class)
            ->give(fn (): Filesystem => Storage::disk('public'));

        $app->bind(CpuReport::class, fn (): CpuReport => new CpuReport);
        $app->bind(MemoryReport::class, fn (): MemoryReport => new MemoryReport);
        $app->tag([CpuReport::class, MemoryReport::class], 'reports');

        $app->bind(ReportAggregator::class, fn (Application $application): ReportAggregator => new ReportAggregator(
            $application->tagged('reports')
        ));

        $app->when(Firewall::class)
            ->needs(Filter::class)
            ->give([
                NullFilter::class,
                ProfanityFilter::class,
            ]);

        $app->bind(DecoratableService::class, fn (): DecoratableService => new DecoratableService('base'));

        $app->extend(DecoratableService::class, fn (DecoratableService $service): DecoratedService => new DecoratedService($service));
    }
}
