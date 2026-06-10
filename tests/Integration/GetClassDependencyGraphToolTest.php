<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Laravel\Mcp\Request;
use Neo4j\LaravelBoost\Boost\Tools\GetClassDependencyGraphTool;
use Neo4j\LaravelBoost\ClassDependencyGraphReader;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Contracts\EventPusherInterface;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Filter;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Firewall;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\PodcastParser;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\RedisEventPusher;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Transistor;
use Neo4j\LaravelBoost\Tests\Integration\Support\InMemoryClassDependencyGraphReader;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\TestCase;

class GetClassDependencyGraphToolTest extends TestCase
{
    private InMemoryClassDependencyGraphReader $graphReader;

    protected function setUp(): void
    {
        parent::setUp();

        ComplexContainerRegistry::register($this->app);

        $writer = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $writer);

        $this->artisan('container:graph')->assertExitCode(0);

        $this->graphReader = InMemoryClassDependencyGraphReader::fromExportRows(
            $writer->classRows,
            $writer->bindingRows,
            $writer->dependencyRows,
            $writer->unresolvedRows,
        );

        $this->app->instance(ClassDependencyGraphReader::class, $this->graphReader);
    }

    public function test_tool_is_registered_in_boost_include_list(): void
    {
        $this->assertContains(
            GetClassDependencyGraphTool::class,
            config('boost.mcp.tools.include', []),
        );
    }

    public function test_tool_schema_marks_class_as_required(): void
    {
        $tool = $this->app->make(GetClassDependencyGraphTool::class);
        $schema = $tool->toArray()['inputSchema'];

        $this->assertContains('class', $schema['required'] ?? []);
        $this->assertArrayHasKey('depth', $schema['properties']);
        $this->assertArrayHasKey('direction', $schema['properties']);
        $this->assertArrayHasKey('include_bindings', $schema['properties']);
        $this->assertArrayHasKey('page', $schema['properties']);
        $this->assertArrayHasKey('per_page', $schema['properties']);
    }

    public function test_tool_paginates_dependencies(): void
    {
        $payload = $this->callTool([
            'class' => Firewall::class,
            'direction' => 'outbound',
            'per_page' => 1,
            'page' => 1,
        ]);

        $this->assertCount(1, $payload['dependencies'] ?? []);
        $this->assertSame(1, $payload['dependencies_pagination']['page']);
        $this->assertSame(1, $payload['dependencies_pagination']['per_page']);
        $this->assertGreaterThanOrEqual(2, $payload['dependencies_pagination']['total']);
        $this->assertTrue($payload['dependencies_pagination']['has_more']);
    }

    public function test_tool_returns_dependencies_for_known_class(): void
    {
        $payload = $this->callTool([
            'class' => Firewall::class,
            'direction' => 'outbound',
        ]);

        $this->assertTrue($payload['found']);
        $this->assertFalse($payload['graph_export_required']);

        $dependencyNames = array_column($payload['dependencies'] ?? [], 'name');
        $this->assertContains(Logger::class, $dependencyNames);
        $this->assertContains(Filter::class, $dependencyNames);
    }

    public function test_tool_succeeds_with_default_bindings_for_class_without_bind_edge(): void
    {
        $payload = $this->callTool([
            'class' => Logger::class,
            'direction' => 'inbound',
        ]);

        $this->assertTrue($payload['found']);
        $this->assertArrayNotHasKey('binding', $payload);
        $this->assertContains(Firewall::class, array_column($payload['dependents'] ?? [], 'name'));
    }

    public function test_tool_returns_binding_for_concrete_implementation(): void
    {
        $payload = $this->callTool([
            'class' => PodcastParser::class,
            'include_bindings' => true,
        ]);

        $this->assertTrue($payload['found']);
        $this->assertSame('legacy.podcast.parser', $payload['binding']['abstract']);
        $this->assertSame(PodcastParser::class, $payload['binding']['concrete']);
    }

    public function test_tool_returns_binding_for_interface(): void
    {
        $payload = $this->callTool([
            'class' => EventPusherInterface::class,
            'include_bindings' => true,
        ]);

        $this->assertTrue($payload['found']);
        $this->assertSame(EventPusherInterface::class, $payload['binding']['abstract']);
        $this->assertSame(RedisEventPusher::class, $payload['binding']['concrete']);
    }

    public function test_tool_returns_dependents_for_inbound_direction(): void
    {
        $payload = $this->callTool([
            'class' => Logger::class,
            'direction' => 'inbound',
        ]);

        $this->assertTrue($payload['found']);
        $dependentNames = array_column($payload['dependents'] ?? [], 'name');
        $this->assertContains(Firewall::class, $dependentNames);
    }

    public function test_tool_returns_export_hint_for_unknown_class(): void
    {
        $payload = $this->callTool([
            'class' => 'App\\Services\\DoesNotExist',
        ]);

        $this->assertFalse($payload['found']);
        $this->assertTrue($payload['graph_export_required']);
        $this->assertStringContainsString('php artisan container:graph', $payload['message']);
    }

    public function test_tool_includes_transistor_dependency_on_podcast_parser(): void
    {
        $payload = $this->callTool([
            'class' => Transistor::class,
            'direction' => 'outbound',
        ]);

        $dependencyNames = array_column($payload['dependencies'] ?? [], 'name');
        $this->assertContains(PodcastParser::class, $dependencyNames);
    }

    public function test_tool_returns_relationship_type_on_dependencies_and_bindings(): void
    {
        $dependencyPayload = $this->callTool([
            'class' => Transistor::class,
            'direction' => 'outbound',
        ]);

        $podcastParserDependency = collect($dependencyPayload['dependencies'] ?? [])
            ->firstWhere('name', PodcastParser::class);

        $this->assertIsArray($podcastParserDependency);
        $this->assertSame('constructor_injection', $podcastParserDependency['type']);
        $this->assertSame('static_analysis', $podcastParserDependency['source']);
        $this->assertSame('high', $podcastParserDependency['confidence']);
        $this->assertSame('declared', $podcastParserDependency['visibility']);

        $bindingPayload = $this->callTool([
            'class' => RedisEventPusher::class,
            'include_bindings' => true,
        ]);

        $this->assertSame('singleton', $bindingPayload['binding']['type'] ?? null);
        $this->assertSame('static_analysis', $bindingPayload['binding']['source'] ?? null);
        $this->assertSame('high', $bindingPayload['binding']['confidence'] ?? null);
    }

    public function test_tool_splits_declared_and_hidden_dependencies_on_current_page(): void
    {
        $payload = $this->callTool([
            'class' => Transistor::class,
            'direction' => 'outbound',
        ]);

        $this->assertArrayHasKey('declared_dependencies', $payload);
        $this->assertArrayHasKey('hidden_dependencies', $payload);
        $this->assertNotEmpty($payload['declared_dependencies']);
        $this->assertSame($payload['declared_dependencies'], $payload['dependencies']);
        $this->assertSame([], $payload['hidden_dependencies']);
    }

    public function test_tool_always_returns_graph_completeness_metadata(): void
    {
        $knownPayload = $this->callTool([
            'class' => Transistor::class,
            'direction' => 'outbound',
        ]);

        $this->assertArrayHasKey('graph_completeness', $knownPayload);
        $this->assertSame('partial', $knownPayload['graph_completeness']['coverage']);
        $this->assertGreaterThan(0, $knownPayload['graph_completeness']['declared_count']);
        $this->assertContains('constructor_injection', $knownPayload['graph_completeness']['detectors_active']);
        $this->assertContains('facade', $knownPayload['graph_completeness']['detectors_pending']);

        $missingPayload = $this->callTool([
            'class' => 'App\\Services\\DoesNotExist',
        ]);

        $this->assertArrayHasKey('graph_completeness', $missingPayload);
        $this->assertSame('unknown', $missingPayload['graph_completeness']['coverage']);
    }

    public function test_tool_keeps_backward_compatible_dependencies_key(): void
    {
        $payload = $this->callTool([
            'class' => Firewall::class,
            'direction' => 'outbound',
        ]);

        $this->assertArrayHasKey('dependencies', $payload);
        $this->assertArrayHasKey('dependencies_pagination', $payload);
        $this->assertContains(Logger::class, array_column($payload['dependencies'], 'name'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callTool(array $arguments): array
    {
        $tool = $this->app->make(GetClassDependencyGraphTool::class);
        $response = $tool->handle(new Request($arguments));

        $this->assertFalse($response->isError());

        $text = $response->content()->toArray()['text'] ?? '';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
