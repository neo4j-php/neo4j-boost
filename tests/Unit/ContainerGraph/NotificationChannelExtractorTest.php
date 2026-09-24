<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Neo4j\LaravelBoost\ContainerGraph\NotificationChannelExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\SmsChannel;

class NotificationChannelExtractorTest extends TestCase
{
    public function test_extracts_builtin_and_extended_channels(): void
    {
        /** @var ChannelManager $manager */
        $manager = $this->app->make(ChannelManager::class);
        $manager->extend('sms', fn () => new SmsChannel);

        $rows = (new NotificationChannelExtractor)->extract($manager);
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertSame('builtin', $byKey['mail']['kind']);
        $this->assertSame(MailChannel::class, $byKey['mail']['resolved_class']);
        $this->assertTrue($byKey['mail']['is_default']);
        $this->assertArrayHasKey('sms', $byKey);
        $this->assertSame('extended', $byKey['sms']['kind']);
        $this->assertSame('', $byKey['sms']['resolved_class']);
    }
}
