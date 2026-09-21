<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Contracts\Mail\Mailable as MailableContract;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Neo4j\LaravelBoost\ContainerGraph\JobHandlerExtractor;
use Neo4j\LaravelBoost\ContainerGraph\MailableExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;

class MailableExtractorTest extends TestCase
{
    public function test_extracts_mailable_as_handled_by_itself(): void
    {
        $rows = (new MailableExtractor)->extract([SimpleInvoiceMailable::class]);
        $match = $this->findRow($rows, SimpleInvoiceMailable::class);

        $this->assertNotNull($match);
        $this->assertSame('SimpleInvoiceMailable', $match['name']);
        $this->assertSame(SimpleInvoiceMailable::class.'@build', $match['action']);
        $this->assertSame(SimpleInvoiceMailable::class, $match['identifier']);
        $this->assertFalse($match['should_queue']);
        $this->assertSame('', $match['mailer']);
        $this->assertFalse($match['unique']);
    }

    public function test_reads_mailer_connection_queue_and_unique_flags(): void
    {
        $rows = (new MailableExtractor)->extract([ConfiguredQueuedMailable::class]);
        $match = $this->findRow($rows, ConfiguredQueuedMailable::class);

        $this->assertNotNull($match);
        $this->assertTrue($match['should_queue']);
        $this->assertSame('ses', $match['mailer']);
        $this->assertSame('redis', $match['connection']);
        $this->assertSame('mails', $match['queue']);
        $this->assertTrue($match['unique']);
        $this->assertSame(ConfiguredQueuedMailable::class.'@send', $match['action']);
    }

    public function test_queued_mailable_is_not_exported_as_job(): void
    {
        $mailableRows = (new MailableExtractor)->extract([ConfiguredQueuedMailable::class]);
        $jobRows = (new JobHandlerExtractor)->extract([ConfiguredQueuedMailable::class]);

        $this->assertNotNull($this->findRow($mailableRows, ConfiguredQueuedMailable::class));
        $this->assertSame([], $jobRows);
    }

    public function test_skips_non_mailable_classes(): void
    {
        $rows = (new MailableExtractor)->extract([NotAMailableService::class]);

        $this->assertSame([], $rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return null|array<string, mixed>
     */
    private function findRow(array $rows, string $key): ?array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        return null;
    }
}

final class SimpleInvoiceMailable extends Mailable
{
    public function build(): self
    {
        return $this->subject('Invoice')->view('mail.invoice');
    }
}

/**
 * Contract-only fixture so connection/queue defaults can be declared without
 * conflicting with Illuminate\Bus\Queueable property composition rules.
 */
final class ConfiguredQueuedMailable implements MailableContract, ShouldBeUnique, ShouldQueue
{
    public $mailer = 'ses';

    public $connection = 'redis';

    public $queue = 'mails';

    public function send($mailer)
    {
        return null;
    }

    public function queue($queue)
    {
        return null;
    }

    public function later($delay, $queue)
    {
        return null;
    }

    public function cc($address, $name = null)
    {
        return $this;
    }

    public function bcc($address, $name = null)
    {
        return $this;
    }

    public function to($address, $name = null)
    {
        return $this;
    }

    public function locale($locale)
    {
        return $this;
    }

    public function mailer($mailer)
    {
        return $this;
    }
}

final class NotAMailableService
{
    public function handle(): void {}
}
