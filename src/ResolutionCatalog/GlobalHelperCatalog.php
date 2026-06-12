<?php

namespace Neo4j\LaravelBoost\ResolutionCatalog;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Psr\Log\LoggerInterface;

/**
 * Global helper → container contract mappings (top hidden-dependency helpers).
 *
 * Sources: rector-laravel ArgumentFuncCallToMethodCall set, Laravel helper docs.
 *
 * @see docs/RESOLUTION_CATALOG.md
 */
final class GlobalHelperCatalog
{
    /** @var list<string> */
    public const TOP_HELPERS = [
        'cache',
        'auth',
        'view',
        'response',
        'redirect',
        'route',
        'event',
        'dispatch',
        'logger',
    ];

    /**
     * @return list<ResolutionCatalogEntry>
     */
    public function entries(): array
    {
        return array_map(
            fn (array $row): ResolutionCatalogEntry => new ResolutionCatalogEntry(
                identifier: $row[0],
                kind: ResolutionCatalogKind::GlobalHelper,
                abstract: $row[1],
                bindsToType: $row[2],
                source: ResolutionCatalogSource::GlobalHelper,
                bindingKey: $row[3],
            ),
            self::ROWS,
        );
    }

    /**
     * @return array<string, ResolutionCatalogEntry>
     */
    public function indexedByHelperName(): array
    {
        $indexed = [];

        foreach ($this->entries() as $entry) {
            $indexed[$entry->identifier] = $entry;
        }

        return $indexed;
    }

    /**
     * [helper, abstract, bindsToType, bindingKey|null]
     *
     * @var list<array{0: string, 1: string, 2: BindsToType, 3: null|string}>
     */
    private const ROWS = [
        ['cache', Factory::class, BindsToType::Singleton, 'cache'],
        ['auth', Guard::class, BindsToType::Singleton, 'auth'],
        ['view', \Illuminate\Contracts\View\Factory::class, BindsToType::Singleton, 'view'],
        ['response', ResponseFactory::class, BindsToType::Singleton, null],
        ['redirect', Redirector::class, BindsToType::Singleton, 'redirect'],
        ['route', UrlGenerator::class, BindsToType::Singleton, 'url'],
        ['event', Dispatcher::class, BindsToType::Singleton, 'events'],
        ['dispatch', Dispatcher::class, BindsToType::Singleton, 'events'],
        ['logger', LoggerInterface::class, BindsToType::Singleton, 'log'],
    ];
}
