<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Neo4j\LaravelBoost\ContainerGraph\BroadcastChannelExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class BroadcastChannelExtractorTest extends TestCase
{
    public function test_extracts_class_based_channel_auth(): void
    {
        $rows = (new BroadcastChannelExtractor)->extract([
            'orders.{orderId}' => OrderChannel::class,
        ], [
            'orders.{orderId}' => ['guards' => ['web', 'api']],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('orders.{orderId}', $rows[0]['key']);
        $this->assertSame('orders.{orderId}', $rows[0]['name']);
        $this->assertSame('web,api', $rows[0]['guards']);
        $this->assertSame(OrderChannel::class, $rows[0]['identifier']);
        $this->assertSame('Class', $rows[0]['identifier_kind']);
        $this->assertSame(OrderChannel::class.'@join', $rows[0]['action']);
    }

    public function test_exports_closure_channels_without_handled_by(): void
    {
        $rows = (new BroadcastChannelExtractor)->extract([
            'private-room.{id}' => function ($user, $id) {
                return true;
            },
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('private-room.{id}', $rows[0]['key']);
        $this->assertSame('', $rows[0]['identifier']);
        $this->assertSame('', $rows[0]['action']);
    }

    public function test_skips_empty_channel_patterns(): void
    {
        $rows = (new BroadcastChannelExtractor)->extract([
            '' => OrderChannel::class,
        ]);

        $this->assertSame([], $rows);
    }
}

final class OrderChannel
{
    public function join(object $user, string $orderId): bool
    {
        return true;
    }
}
