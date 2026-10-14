<?php

namespace Neo4j\LaravelBoost\Tests\Unit\ContainerGraph;

use Illuminate\Foundation\Console\ClosureCommand;
use Neo4j\LaravelBoost\ContainerGraph\ArtisanCommandExtractor;
use Neo4j\LaravelBoost\Tests\TestCase;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Commands\HiddenInvokableCommand;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Commands\PlainSymfonyCommand;
use Neo4j\LaravelBoost\Tests\Unit\ContainerGraph\Fixtures\Commands\PruneInvoicesCommand;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\LazyCommand;

class ArtisanCommandExtractorTest extends TestCase
{
    public function test_maps_class_command_to_handle_and_skips_alias_keys(): void
    {
        $command = new PruneInvoicesCommand;

        $rows = (new ArtisanCommandExtractor)->extract([
            'invoices:prune' => $command,
            'invoices:clean' => $command,
            'prune-invoices' => $command,
        ]);

        $this->assertSame([[
            'key' => 'invoices:prune',
            'name' => 'invoices:prune',
            'description' => 'Prune stale invoices',
            'hidden' => false,
            'aliases' => 'invoices:clean,prune-invoices',
            'kind' => 'class',
            'source' => 'app',
            'action' => PruneInvoicesCommand::class.'@handle',
            'identifier' => PruneInvoicesCommand::class,
            'identifier_kind' => 'Class',
        ]], $rows);
    }

    public function test_hidden_invokable_command_uses_invoke_action(): void
    {
        $rows = (new ArtisanCommandExtractor)->extract([
            'internal:warm' => new HiddenInvokableCommand,
        ]);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['hidden']);
        $this->assertSame(HiddenInvokableCommand::class.'@__invoke', $rows[0]['action']);
    }

    public function test_plain_symfony_command_uses_execute_action(): void
    {
        $rows = (new ArtisanCommandExtractor)->extract([
            'plain:run' => new PlainSymfonyCommand,
        ]);

        $this->assertSame(PlainSymfonyCommand::class.'@execute', $rows[0]['action']);
    }

    public function test_closure_command_has_no_handler(): void
    {
        $command = (new ClosureCommand('reports:ping', fn () => 0))->purpose('Ping reports');

        $rows = (new ArtisanCommandExtractor)->extract(['reports:ping' => $command]);

        $this->assertCount(1, $rows);
        $this->assertSame('closure', $rows[0]['kind']);
        $this->assertSame('app', $rows[0]['source']);
        $this->assertSame('Ping reports', $rows[0]['description']);
        $this->assertSame('', $rows[0]['action']);
        $this->assertSame('', $rows[0]['identifier']);
        $this->assertSame('', $rows[0]['identifier_kind']);
    }

    public function test_lazy_command_is_unwrapped(): void
    {
        $lazy = new LazyCommand('invoices:prune', [], 'Prune stale invoices', false, fn () => new PruneInvoicesCommand);

        $rows = (new ArtisanCommandExtractor)->extract(['invoices:prune' => $lazy]);

        $this->assertSame(PruneInvoicesCommand::class, $rows[0]['identifier']);
    }

    public function test_vendor_symfony_console_command_is_framework_source(): void
    {
        $rows = (new ArtisanCommandExtractor)->extract(['help' => new HelpCommand]);

        $this->assertSame('framework', $rows[0]['source']);
    }

    public function test_non_command_entries_are_skipped(): void
    {
        $this->assertSame([], (new ArtisanCommandExtractor)->extract(['bogus' => new \stdClass]));
    }
}
