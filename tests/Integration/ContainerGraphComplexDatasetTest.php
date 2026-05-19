<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Neo4j\LaravelBoost\Console\ContainerGraphCommand;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Filter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Firewall;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\RedisEventPusher;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Transistor;
use Neo4j\LaravelBoost\Tests\TestCase;
use ReflectionMethod;

/**
 * Integration coverage for container:graph against a Laravel 13-style binding dataset.
 *
 * @see https://laravel.com/docs/13.x/container
 */
class ContainerGraphComplexDatasetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ComplexContainerRegistry::register($this->app);
    }

    public function test_complex_dataset_exports_interface_class_and_alias_bindings(): void
    {
        $payload = $this->extractGraphPayload();

        $interfaceBinding = $this->findBinding($payload['bindingRows'], EventPusherInterface::class);
        $this->assertNotNull($interfaceBinding);
        $this->assertSame('Interface', $interfaceBinding['abstractKind']);
        $this->assertSame(RedisEventPusher::class, $interfaceBinding['concrete']);
        $this->assertSame('Class', $interfaceBinding['concreteKind']);

        $aliasBinding = $this->findBinding($payload['bindingRows'], 'billing.currency');
        $this->assertNotNull($aliasBinding);
        $this->assertSame('USD', $aliasBinding['concrete']);
        $this->assertSame('Alias', $aliasBinding['concreteKind']);

        $bindIfBinding = $this->findBinding($payload['bindingRows'], 'legacy.podcast.parser');
        $this->assertNotNull($bindIfBinding);
        $this->assertSame(PodcastParser::class, $bindIfBinding['concrete']);
    }

    public function test_complex_dataset_marks_singleton_and_closure_bindings(): void
    {
        $payload = $this->extractGraphPayload();

        $singletonBinding = $this->findBinding($payload['bindingRows'], RedisEventPusher::class);
        $this->assertNotNull($singletonBinding);
        $this->assertTrue($singletonBinding['shared']);

        $closureBinding = $this->findBinding($payload['bindingRows'], 'reports.analyzer');
        $this->assertNotNull($closureBinding);
        $this->assertContains($closureBinding['concreteKind'], ['Closure', 'Class', 'Alias']);
    }

    public function test_complex_dataset_extracts_constructor_dependency_edges(): void
    {
        $payload = $this->extractGraphPayload();

        $this->assertTrue($this->hasDependencyEdge(
            $payload['dependencyRows'],
            Transistor::class,
            PodcastParser::class
        ));

        $this->assertTrue($this->hasDependencyEdge(
            $payload['dependencyRows'],
            Firewall::class,
            Logger::class
        ));

        $this->assertTrue($this->hasDependencyEdge(
            $payload['dependencyRows'],
            Firewall::class,
            Filter::class
        ));
    }

    public function test_complex_dataset_includes_fixture_classes_in_inspection_set(): void
    {
        $payload = $this->extractGraphPayload();

        $this->assertContains(Transistor::class, $payload['classes']);
        $this->assertContains(Firewall::class, $payload['classes']);
        $this->assertGreaterThanOrEqual(10, count($payload['bindingRows']));
        $this->assertGreaterThanOrEqual(3, count($payload['dependencyRows']));
    }

    public function test_container_graph_dry_run_succeeds_with_complex_dataset(): void
    {
        $this->artisan('container:graph', ['--dry-run' => true])
            ->expectsOutputToContain('Container graph summary:')
            ->expectsOutputToContain('Bindings:')
            ->expectsOutputToContain('Dry run complete')
            ->assertExitCode(0);
    }

    /**
     * @return array{
     *     bindingRows: array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool}>,
     *     concreteClasses: array<int, string>,
     *     classes: array<int, string>,
     *     dependencyRows: array<int, array{class: string, dependency: string, dependencyKind: string}>,
     *     unresolvedRows: array<int, array{class: string, name: string, reason: string}>
     * }
     */
    private function extractGraphPayload(): array
    {
        $command = $this->app->make(ContainerGraphCommand::class);

        $extractBindings = new ReflectionMethod($command, 'extractBindingRows');
        $extractBindings->setAccessible(true);
        /** @var array{0: array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool}>, 1: array<int, string>} $bindingResult */
        $bindingResult = $extractBindings->invoke($command);
        [$bindingRows, $concreteClasses] = $bindingResult;

        $extractCustomClasses = new ReflectionMethod($command, 'extractCustomClassNames');
        $extractCustomClasses->setAccessible(true);
        /** @var array<int, string> $customClasses */
        $customClasses = $extractCustomClasses->invoke($command);

        $mergeClasses = new ReflectionMethod($command, 'mergeClassLists');
        $mergeClasses->setAccessible(true);
        /** @var array<int, string> $classes */
        $classes = $mergeClasses->invoke($command, $concreteClasses, $customClasses);

        $extractDependencies = new ReflectionMethod($command, 'extractDependencyRows');
        $extractDependencies->setAccessible(true);
        /** @var array{0: array<int, array{class: string, dependency: string, dependencyKind: string}>, 1: array<int, array{class: string, name: string, reason: string}>} $dependencyResult */
        $dependencyResult = $extractDependencies->invoke($command, $classes);
        [$dependencyRows, $unresolvedRows] = $dependencyResult;

        return [
            'bindingRows' => $bindingRows,
            'concreteClasses' => $concreteClasses,
            'classes' => $classes,
            'dependencyRows' => $dependencyRows,
            'unresolvedRows' => $unresolvedRows,
        ];
    }

    /**
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool}>  $bindingRows
     * @return null|array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool}
     */
    private function findBinding(array $bindingRows, string $abstract): ?array
    {
        foreach ($bindingRows as $row) {
            if ($row['abstract'] === $abstract) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string}>  $dependencyRows
     */
    private function hasDependencyEdge(array $dependencyRows, string $class, string $dependency): bool
    {
        foreach ($dependencyRows as $row) {
            if ($row['class'] === $class && $row['dependency'] === $dependency) {
                return true;
            }
        }

        return false;
    }
}
