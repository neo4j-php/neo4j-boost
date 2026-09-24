<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Notifications\Channels\MailChannel;
use Neo4j\LaravelBoost\ContainerGraph\JobHandlerExtractor;
use Neo4j\LaravelBoost\ContainerGraph\NotificationHandlerExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\ClassChannelNotification;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\ConstructorHeavyNotification;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\InvoicePaidNotification;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\QueuedInvoiceNotification;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\SmsChannel;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\StringViaNotification;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Notifications\TypedNotifiableNotification;

class NotificationHandlerExtractorTest extends TestCase
{
    public function test_extracts_notifications_and_via_channels(): void
    {
        $extracted = (new NotificationHandlerExtractor)->extract([
            InvoicePaidNotification::class,
            ConstructorHeavyNotification::class,
            ClassChannelNotification::class,
            'App\\DoesNotExist\\Missing',
        ]);

        $byKey = [];
        foreach ($extracted['notifications'] as $row) {
            $byKey[$row['key']] = $row;
        }

        $this->assertArrayHasKey(InvoicePaidNotification::class, $byKey);
        $this->assertSame(InvoicePaidNotification::class.'@via', $byKey[InvoicePaidNotification::class]['action']);
        $this->assertFalse($byKey[InvoicePaidNotification::class]['should_queue']);

        $this->assertArrayHasKey(ConstructorHeavyNotification::class, $byKey);
        $this->assertArrayHasKey(ClassChannelNotification::class, $byKey);

        $channelsByNotification = [];
        foreach ($extracted['uses_channel'] as $row) {
            $channelsByNotification[$row['notification_key']][] = $row;
        }

        $invoiceChannels = [];
        foreach ($channelsByNotification[InvoicePaidNotification::class] ?? [] as $row) {
            $invoiceChannels[$row['channel_key']] = $row;
        }
        $this->assertSame(0, $invoiceChannels['mail']['order']);
        $this->assertSame('builtin', $invoiceChannels['mail']['channel_kind']);
        $this->assertSame(MailChannel::class, $invoiceChannels['mail']['resolved_class']);
        $this->assertSame(1, $invoiceChannels['database']['order']);

        $classChannel = $channelsByNotification[ClassChannelNotification::class][0];
        $this->assertSame(SmsChannel::class, $classChannel['channel_key']);
        $this->assertSame('class', $classChannel['channel_kind']);
    }

    public function test_queued_notification_is_not_exported_as_job(): void
    {
        $notificationRows = (new NotificationHandlerExtractor)->extract([QueuedInvoiceNotification::class]);
        $jobRows = (new JobHandlerExtractor)->extract([QueuedInvoiceNotification::class]);

        $this->assertCount(1, $notificationRows['notifications']);
        $this->assertTrue($notificationRows['notifications'][0]['should_queue']);
        $this->assertSame('redis', $notificationRows['notifications'][0]['connection']);
        $this->assertSame('notifications', $notificationRows['notifications'][0]['queue']);
        $this->assertSame([], $jobRows);
    }

    public function test_extracts_channels_when_via_typehints_a_concrete_notifiable(): void
    {
        $extracted = (new NotificationHandlerExtractor)->extract([
            TypedNotifiableNotification::class,
        ]);

        $this->assertCount(1, $extracted['notifications']);
        $this->assertSame(
            ['mail', 'database'],
            array_column($extracted['uses_channel'], 'channel_key'),
        );
    }

    public function test_extracts_channels_when_via_returns_a_string(): void
    {
        $extracted = (new NotificationHandlerExtractor)->extract([
            StringViaNotification::class,
        ]);

        $this->assertCount(1, $extracted['notifications']);
        $this->assertCount(1, $extracted['uses_channel']);
        $this->assertSame('mail', $extracted['uses_channel'][0]['channel_key']);
        $this->assertSame('builtin', $extracted['uses_channel'][0]['channel_kind']);
    }
}
