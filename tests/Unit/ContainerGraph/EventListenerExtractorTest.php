<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;
use Neo4j\LaravelBoost\ContainerGraph\EventListenerExtractor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Events\OrderShipped;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Listeners\OrderShippedListener;
use Neo4j\LaravelBoost\Tests\TestCase;

class EventListenerExtractorTest extends TestCase
{
    public function test_extracts_class_listener_as_handled_by_identifier(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen(OrderShipped::class, OrderShippedListener::class);

        $rows = (new EventListenerExtractor)->extract($events);
        $match = $this->findRow($rows, OrderShipped::class, OrderShippedListener::class);

        $this->assertNotNull($match);
        $this->assertSame('OrderShipped', $match['name']);
        $this->assertSame(OrderShippedListener::class.'@handle', $match['action']);
        $this->assertSame('Class', $match['identifier_kind']);
    }

    public function test_extracts_class_at_method_and_array_callable_forms(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen(OrderShipped::class, OrderShippedListener::class.'@handle');
        $events->listen('orders.refunded', [OrderShippedListener::class, 'handle']);

        $rows = (new EventListenerExtractor)->extract($events);

        $classAt = $this->findRow($rows, OrderShipped::class, OrderShippedListener::class);
        $this->assertNotNull($classAt);
        $this->assertSame(OrderShippedListener::class.'@handle', $classAt['action']);

        $arrayForm = $this->findRow($rows, 'orders.refunded', OrderShippedListener::class);
        $this->assertNotNull($arrayForm);
        $this->assertSame('orders.refunded', $arrayForm['name']);
        $this->assertSame(OrderShippedListener::class.'@handle', $arrayForm['action']);
    }

    public function test_skips_closure_listeners(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $before = count((new EventListenerExtractor)->extract($events));
        $events->listen(OrderShipped::class, static function (): void {});

        $rows = (new EventListenerExtractor)->extract($events);
        $matches = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['key'] === OrderShipped::class,
        ));

        $this->assertSame([], $matches);
        $this->assertSame($before, count($rows));
    }

    public function test_exports_queued_listener_class_string(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen(OrderShipped::class, QueuedOrderShippedListener::class);

        $match = $this->findRow(
            (new EventListenerExtractor)->extract($events),
            OrderShipped::class,
            QueuedOrderShippedListener::class,
        );

        $this->assertNotNull($match);
        $this->assertSame(QueuedOrderShippedListener::class.'@handle', $match['action']);
    }

    public function test_dedupes_duplicate_listener_registrations(): void
    {
        /** @var Dispatcher $events */
        $events = $this->app->make('events');
        $events->listen(OrderShipped::class, OrderShippedListener::class);
        $events->listen(OrderShipped::class, OrderShippedListener::class);

        $matches = array_values(array_filter(
            (new EventListenerExtractor)->extract($events),
            static fn (array $row): bool => $row['key'] === OrderShipped::class
                && $row['identifier'] === OrderShippedListener::class,
        ));

        $this->assertCount(1, $matches);
    }

    /**
     * @param  array<int, array{key: string, name: string, action: string, identifier: string, identifier_kind: string}>  $rows
     * @return null|array{key: string, name: string, action: string, identifier: string, identifier_kind: string}
     */
    private function findRow(array $rows, string $eventKey, string $identifier): ?array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $eventKey && $row['identifier'] === $identifier) {
                return $row;
            }
        }

        return null;
    }
}

final class QueuedOrderShippedListener implements ShouldQueue
{
    public function handle(OrderShipped $event): void
    {
        //
    }
}
