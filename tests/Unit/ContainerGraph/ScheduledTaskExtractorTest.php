<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Neo4j\LaravelBoost\ContainerGraph\ScheduledTaskExtractor;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Commands\SyncReportsCommand;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Jobs\ProcessInvoiceJob;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\ScheduledCallbackTarget;

class ScheduledTaskExtractorTest extends TestCase
{
    public function test_extracts_artisan_command_schedule_with_handled_by(): void
    {
        $this->registerCommand(SyncReportsCommand::class);

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('reports:sync')->daily()->withoutOverlapping();

        $rows = (new ScheduledTaskExtractor)->extract($schedule);
        $match = $this->findByIdentifier($rows, SyncReportsCommand::class);

        $this->assertNotNull($match);
        $this->assertSame('command', $match['kind']);
        $this->assertSame('0 0 * * *', $match['expression']);
        $this->assertTrue($match['without_overlapping']);
        $this->assertSame(SyncReportsCommand::class.'@handle', $match['action']);
        $this->assertSame('Class', $match['identifier_kind']);
        $this->assertSame('SyncReportsCommand', $match['name']);
    }

    public function test_extracts_scheduled_job_class(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->job(ProcessInvoiceJob::class)->hourly()->onOneServer();

        $match = $this->findByIdentifier(
            (new ScheduledTaskExtractor)->extract($schedule),
            ProcessInvoiceJob::class,
        );

        $this->assertNotNull($match);
        $this->assertSame('job', $match['kind']);
        $this->assertSame('0 * * * *', $match['expression']);
        $this->assertTrue($match['on_one_server']);
        $this->assertSame(ProcessInvoiceJob::class.'@handle', $match['action']);
        $this->assertSame('ProcessInvoiceJob', $match['name']);
    }

    public function test_extracts_array_callable_callback(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->call([ScheduledCallbackTarget::class, 'run'])->everyFiveMinutes();

        $match = $this->findByIdentifier(
            (new ScheduledTaskExtractor)->extract($schedule),
            ScheduledCallbackTarget::class,
        );

        $this->assertNotNull($match);
        $this->assertSame('callback', $match['kind']);
        $this->assertSame('*/5 * * * *', $match['expression']);
        $this->assertSame(ScheduledCallbackTarget::class.'@run', $match['action']);
    }

    public function test_exports_closure_callback_without_handled_by_identifier(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->call(static function (): void {})->dailyAt('13:00');

        $rows = (new ScheduledTaskExtractor)->extract($schedule);
        $closures = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['kind'] === 'callback' && $row['identifier'] === '',
        ));

        $this->assertNotEmpty($closures);
        $this->assertSame('', $closures[0]['action']);
        $this->assertSame('0 13 * * *', $closures[0]['expression']);
    }

    public function test_exports_exec_without_identifier(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->exec('ls -la')->weekly();

        $rows = (new ScheduledTaskExtractor)->extract($schedule);
        $exec = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['kind'] === 'exec',
        ));

        $this->assertCount(1, $exec);
        $this->assertSame('', $exec[0]['identifier']);
        $this->assertStringContainsString('ls -la', $exec[0]['command']);
    }

    public function test_dedupes_identical_schedule_registrations(): void
    {
        $this->registerCommand(SyncReportsCommand::class);

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('reports:sync')->daily();
        $schedule->command('reports:sync')->daily();

        $matches = array_values(array_filter(
            (new ScheduledTaskExtractor)->extract($schedule),
            static fn (array $row): bool => $row['identifier'] === SyncReportsCommand::class,
        ));

        $this->assertCount(1, $matches);
    }

    /**
     * @param  class-string  $command
     */
    private function registerCommand(string $command): void
    {
        $this->app->make(Kernel::class)
            ->registerCommand($this->app->make($command));
    }

    /**
     * @param  array<int, array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}>  $rows
     * @return null|array{key: string, name: string, expression: string, command: string, description: string, timezone: string, kind: string, without_overlapping: bool, on_one_server: bool, run_in_background: bool, even_in_maintenance_mode: bool, action: string, identifier: string, identifier_kind: string}
     */
    private function findByIdentifier(array $rows, string $identifier): ?array
    {
        foreach ($rows as $row) {
            if ($row['identifier'] === $identifier) {
                return $row;
            }
        }

        return null;
    }
}
