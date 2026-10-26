<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Events\Dispatcher;
use Neo4j\LaravelBoost\ContainerGraph\EloquentModelEventExtractor;
use Neo4j\LaravelBoost\ContainerGraph\EventListenerExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Events\InvoiceCreated;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models\AttributedInvoice;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Models\Invoice;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Observers\AttributedInvoiceObserver;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Observers\InvoiceObserver;

class EloquentModelEventExtractorTest extends TestCase
{
    public function test_extracts_class_observers_from_the_live_dispatcher(): void
    {
        Invoice::observe(InvoiceObserver::class);

        $rows = (new EloquentModelEventExtractor)->extract([Invoice::class]);
        $created = $this->findObserverRow($rows, Invoice::class, 'created', InvoiceObserver::class);
        $deleted = $this->findObserverRow($rows, Invoice::class, 'deleted', InvoiceObserver::class);

        $this->assertNotNull($created);
        $this->assertSame('eloquent.created: '.Invoice::class, $created['key']);
        $this->assertSame('Invoice created', $created['name']);
        $this->assertSame('observer', $created['source']);
        $this->assertSame(InvoiceObserver::class.'@created', $created['action']);
        $this->assertSame('Class', $created['identifier_kind']);

        $this->assertNotNull($deleted);
        $this->assertSame(InvoiceObserver::class.'@deleted', $deleted['action']);
    }

    public function test_extracts_observed_by_attribute_without_booting_the_model(): void
    {
        $rows = (new EloquentModelEventExtractor)->extract([AttributedInvoice::class], new Dispatcher);

        $match = $this->findObserverRow(
            $rows,
            AttributedInvoice::class,
            'saved',
            AttributedInvoiceObserver::class,
        );

        $this->assertNotNull($match);
        $this->assertSame('observed_by', $match['source']);
        $this->assertSame(AttributedInvoiceObserver::class.'@saved', $match['action']);
    }

    public function test_extracts_dispatches_events_map(): void
    {
        $rows = (new EloquentModelEventExtractor)->extract([Invoice::class], new Dispatcher);

        $match = $this->findDispatchedRow($rows, Invoice::class, 'created', InvoiceCreated::class);

        $this->assertNotNull($match);
        $this->assertSame('dispatches_events', $match['source']);
        $this->assertSame('', $match['identifier']);
        $this->assertSame(InvoiceCreated::class, $match['event_class']);
        $this->assertSame('Class', $match['event_class_kind']);
    }

    public function test_skips_closure_observers_on_the_dispatcher(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen('eloquent.saving: '.Invoice::class, static function (): void {});

        $rows = (new EloquentModelEventExtractor)->extract([], $events);
        $matches = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['key'] === 'eloquent.saving: '.Invoice::class,
        ));

        $this->assertSame([], $matches);
    }

    public function test_event_listener_extractor_no_longer_exports_eloquent_listeners(): void
    {
        Invoice::observe(InvoiceObserver::class);

        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $rows = (new EventListenerExtractor)->extract($events);

        $eloquent = array_values(array_filter(
            $rows,
            static fn (array $row): bool => str_starts_with($row['key'], 'eloquent.'),
        ));

        $this->assertSame([], $eloquent);
    }

    public function test_dedupes_duplicate_observer_registrations(): void
    {
        Invoice::observe(InvoiceObserver::class);
        Invoice::observe(InvoiceObserver::class);

        $matches = array_values(array_filter(
            (new EloquentModelEventExtractor)->extract([Invoice::class]),
            static fn (array $row): bool => $row['model'] === Invoice::class
                && $row['lifecycle'] === 'created'
                && $row['identifier'] === InvoiceObserver::class,
        ));

        $this->assertCount(1, $matches);
    }

    /**
     * @param  array<int, array{key: string, name: string, lifecycle: string, source: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string, event_class: string, event_class_kind: string}>  $rows
     * @return null|array{key: string, name: string, lifecycle: string, source: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string, event_class: string, event_class_kind: string}
     */
    private function findObserverRow(array $rows, string $model, string $lifecycle, string $identifier): ?array
    {
        foreach ($rows as $row) {
            if ($row['model'] === $model && $row['lifecycle'] === $lifecycle && $row['identifier'] === $identifier) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{key: string, name: string, lifecycle: string, source: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string, event_class: string, event_class_kind: string}>  $rows
     * @return null|array{key: string, name: string, lifecycle: string, source: string, model: string, model_kind: string, identifier: string, identifier_kind: string, action: string, event_class: string, event_class_kind: string}
     */
    private function findDispatchedRow(array $rows, string $model, string $lifecycle, string $eventClass): ?array
    {
        foreach ($rows as $row) {
            if ($row['model'] === $model && $row['lifecycle'] === $lifecycle && $row['event_class'] === $eventClass) {
                return $row;
            }
        }

        return null;
    }
}
