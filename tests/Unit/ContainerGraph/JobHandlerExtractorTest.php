<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Neo4j\LaravelBoost\ContainerGraph\JobHandlerExtractor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs\ProcessInvoiceJob;
use Neo4j\LaravelBoost\Tests\TestCase;

class JobHandlerExtractorTest extends TestCase
{
    public function test_extracts_queued_job_as_handled_by_itself(): void
    {
        $rows = (new JobHandlerExtractor)->extract([ProcessInvoiceJob::class]);
        $match = $this->findRow($rows, ProcessInvoiceJob::class);

        $this->assertNotNull($match);
        $this->assertSame('ProcessInvoiceJob', $match['name']);
        $this->assertSame(ProcessInvoiceJob::class.'@handle', $match['action']);
        $this->assertSame(ProcessInvoiceJob::class, $match['identifier']);
        $this->assertTrue($match['should_queue']);
        $this->assertFalse($match['unique']);
    }

    public function test_extracts_sync_job_and_invokable_job(): void
    {
        $rows = (new JobHandlerExtractor)->extract([
            SyncReportJob::class,
            InvokableImportJob::class,
        ]);

        $sync = $this->findRow($rows, SyncReportJob::class);
        $this->assertNotNull($sync);
        $this->assertFalse($sync['should_queue']);
        $this->assertSame(SyncReportJob::class.'@handle', $sync['action']);

        $invokable = $this->findRow($rows, InvokableImportJob::class);
        $this->assertNotNull($invokable);
        $this->assertSame(InvokableImportJob::class.'@__invoke', $invokable['action']);
    }

    public function test_reads_connection_queue_and_unique_flags(): void
    {
        $rows = (new JobHandlerExtractor)->extract([ConfiguredMailJob::class]);
        $match = $this->findRow($rows, ConfiguredMailJob::class);

        $this->assertNotNull($match);
        $this->assertSame('redis', $match['connection']);
        $this->assertSame('mails', $match['queue']);
        $this->assertTrue($match['unique']);
    }

    public function test_skips_queued_listeners(): void
    {
        $rows = (new JobHandlerExtractor)->extract([QueuedOrderListener::class]);

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

final class SyncReportJob
{
    public function handle(): void {}
}

final class InvokableImportJob implements ShouldQueue
{
    public function __invoke(): void {}
}

final class ConfiguredMailJob implements ShouldBeUnique, ShouldQueue
{
    public $connection = 'redis';

    public $queue = 'mails';

    public function handle(): void {}
}

final class QueuedOrderListener implements ShouldQueue
{
    public function handle(object $event): void {}
}
