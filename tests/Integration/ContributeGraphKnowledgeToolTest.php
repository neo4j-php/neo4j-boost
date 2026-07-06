<?php

namespace Neo4j\LaravelBoost\Tests\Integration;

use Laravel\Mcp\Request;
use Neo4j\LaravelBoost\Boost\Tools\ContributeGraphKnowledgeTool;
use Neo4j\LaravelBoost\Boost\Tools\GetClassDependencyGraphTool;
use Neo4j\LaravelBoost\ClassDependencyGraphReader;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\GraphKnowledgeContributor;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\ComplexContainerRegistry;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\Logger;
use Neo4j\LaravelBoost\Tests\Integration\Fixtures\ContainerGraph\Services\NullFilter;
use Neo4j\LaravelBoost\Tests\Integration\Support\InMemoryClassDependencyGraphReader;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingContainerGraphWriter;
use Neo4j\LaravelBoost\Tests\Integration\Support\RecordingGraphKnowledgeContributor;
use Neo4j\LaravelBoost\Tests\TestCase;

class ContributeGraphKnowledgeToolTest extends TestCase
{
    private InMemoryClassDependencyGraphReader $graphReader;

    private RecordingGraphKnowledgeContributor $contributor;

    protected function setUp(): void
    {
        parent::setUp();

        ComplexContainerRegistry::register($this->app);

        $writer = new RecordingContainerGraphWriter;
        $this->app->instance(ContainerGraphWriter::class, $writer);

        $this->artisan('container:graph')->assertExitCode(0);

        $this->graphReader = InMemoryClassDependencyGraphReader::fromExportRows(
            $writer->instanceRows,
            $writer->bindingRows,
            $writer->dependencyChainRows,
        );

        $this->contributor = new RecordingGraphKnowledgeContributor;
        $this->contributor->attachGraphReader($this->graphReader);

        $this->app->instance(ClassDependencyGraphReader::class, $this->graphReader);
        $this->app->instance(GraphKnowledgeContributor::class, $this->contributor);
    }

    public function test_tool_is_registered_in_boost_include_list(): void
    {
        $this->assertContains(
            ContributeGraphKnowledgeTool::class,
            config('boost.mcp.tools.include', []),
        );
    }

    public function test_medium_confidence_proposal_requires_confirmation_without_persisting(): void
    {
        $payload = $this->callContributeTool([
            'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
            'from' => Logger::class,
            'to' => NullFilter::class,
            'confidence' => GraphKnowledgeContributor::CONFIDENCE_MEDIUM,
            'reason' => 'Resolved from service provider at runtime',
        ]);

        $this->assertSame('confirmation_required', $payload['status']);
        $this->assertArrayHasKey('proposal', $payload);
        $this->assertSame(Logger::class, $payload['proposal']['from']);
        $this->assertSame(NullFilter::class, $payload['proposal']['to']);
        $this->assertSame(DependsOnType::ServiceLocation->value, $payload['proposal']['type']);
        $this->assertSame([], $this->contributor->persistedContributions());

        $graph = $this->callGetGraphTool([
            'class' => Logger::class,
            'direction' => 'outbound',
        ]);

        $dependencyNames = array_column($graph['dependencies'] ?? [], 'name');
        $this->assertNotContains(NullFilter::class, $dependencyNames);
    }

    public function test_propose_confirm_and_read_back_user_contributed_edge(): void
    {
        $proposal = $this->callContributeTool([
            'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
            'from' => Logger::class,
            'to' => NullFilter::class,
            'confidence' => GraphKnowledgeContributor::CONFIDENCE_LOW,
            'reason' => 'Agent inferred from AppServiceProvider binding',
        ]);

        $this->assertSame('confirmation_required', $proposal['status']);

        $confirmed = $this->callContributeTool([
            'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
            'from' => Logger::class,
            'to' => NullFilter::class,
            'confidence' => GraphKnowledgeContributor::CONFIDENCE_LOW,
            'confirmed' => true,
            'reason' => 'Agent inferred from AppServiceProvider binding',
        ]);

        $this->assertSame('persisted', $confirmed['status']);
        $this->assertSame(GraphKnowledgeContributor::SOURCE_USER, $confirmed['source']);
        $this->assertCount(1, $this->contributor->persistedContributions());

        $graph = $this->callGetGraphTool([
            'class' => Logger::class,
            'direction' => 'outbound',
        ]);

        $matching = array_values(array_filter(
            $graph['dependencies'] ?? [],
            static fn (array $dependency): bool => ($dependency['name'] ?? '') === NullFilter::class,
        ));

        $this->assertCount(1, $matching);
        $this->assertSame(GraphKnowledgeContributor::SOURCE_USER, $matching[0]['source']);
        $this->assertSame(DependsOnType::ServiceLocation->value, $matching[0]['type']);
    }

    public function test_high_confidence_persists_immediately_with_agent_source(): void
    {
        $payload = $this->callContributeTool([
            'relationship' => GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
            'from' => Logger::class,
            'to' => NullFilter::class,
            'confidence' => GraphKnowledgeContributor::CONFIDENCE_HIGH,
        ]);

        $this->assertSame('persisted', $payload['status']);
        $this->assertSame(GraphKnowledgeContributor::SOURCE_AGENT, $payload['source']);
        $this->assertCount(1, $this->contributor->persistedContributions());
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callContributeTool(array $arguments): array
    {
        $tool = $this->app->make(ContributeGraphKnowledgeTool::class);
        $response = $tool->handle(new Request($arguments));

        $this->assertFalse($response->isError());

        $text = $response->content()->toArray()['text'] ?? '';
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $text, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function callGetGraphTool(array $arguments): array
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
