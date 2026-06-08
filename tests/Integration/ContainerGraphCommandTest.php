<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Console\ContainerGraphCommand;
use Neo4j\LaravelBoost\Tests\TestCase;
use ReflectionMethod;

class ContainerGraphCommandTest extends TestCase
{
    public function test_container_graph_dry_run_exits_successfully(): void
    {
        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);
    }

    public function test_extract_binding_rows_keeps_non_class_bindings(): void
    {
        $this->app->bind('test.binding.alias', fn (): object => new \stdClass);

        $command = $this->app->make(ContainerGraphCommand::class);
        $method = new ReflectionMethod($command, 'extractBindingRows');
        $method->setAccessible(true);

        /** @var array{0: array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool}>, 1: array<int, string>} $result */
        $result = $method->invoke($command);
        [$bindingRows] = $result;

        $matching = array_values(array_filter(
            $bindingRows,
            static fn (array $row): bool => $row['abstract'] === 'test.binding.alias'
        ));

        $this->assertNotEmpty($matching);
        $this->assertArrayHasKey('concreteKind', $matching[0]);
    }

    public function test_extract_custom_class_names_ignores_autoload_dev_paths(): void
    {
        $composerJson = base_path('composer.json');
        $backup = (string) file_get_contents($composerJson);
        $decoded = json_decode($backup, true);
        $this->assertIsArray($decoded);

        $decoded['autoload-dev']['psr-4']['Tests\\'] = 'tests/';
        file_put_contents($composerJson, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        try {
            $command = $this->app->make(ContainerGraphCommand::class);
            $method = new ReflectionMethod($command, 'extractCustomClassNames');
            $method->setAccessible(true);

            /** @var array<int, string> $classes */
            $classes = $method->invoke($command);

            $this->assertNotContains('Tests\\Pest', $classes);
        } finally {
            file_put_contents($composerJson, $backup);
        }
    }
}
