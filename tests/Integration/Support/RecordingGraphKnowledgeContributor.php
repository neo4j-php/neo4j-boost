<?php

namespace Neo4j\LaravelBoost\Tests\Integration\Support;

use Neo4j\LaravelBoost\GraphKnowledgeContributor;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Tests\Integration\Support\Stubs\UnusedContainerGraphConnection;

/**
 * In-memory stand-in for GraphKnowledgeContributor used in integration tests.
 */
class RecordingGraphKnowledgeContributor extends GraphKnowledgeContributor
{
    /** @var array<int, array<string, mixed>> */
    public array $persistedContributions = [];

    private ?InMemoryClassDependencyGraphReader $graphReader = null;

    public function __construct()
    {
        parent::__construct(new UnusedContainerGraphConnection);
    }

    public function attachGraphReader(InMemoryClassDependencyGraphReader $reader): void
    {
        $this->graphReader = $reader;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function persistedContributions(): array
    {
        return $this->persistedContributions;
    }

    protected function persist(
        string $relationship,
        string $from,
        string $to,
        string $confidence,
        string $source,
        ?string $reason,
        ?bool $shared,
        ?string $dependsOnType,
    ): void {
        $contribution = [
            'relationship' => $relationship,
            'from' => $from,
            'to' => $to,
            'confidence' => $confidence,
            'source' => $source,
            'reason' => $reason,
            'shared' => $shared,
            'depends_on_type' => $dependsOnType,
        ];

        if ($relationship === GraphKnowledgeContributor::RELATIONSHIP_BINDS_TO) {
            $contribution['type'] = BindsToType::fromShared($shared ?? false)->value;
        } else {
            $contribution['type'] = $dependsOnType ?? DependsOnType::ServiceLocation->value;
        }

        $this->persistedContributions[] = $contribution;

        if ($this->graphReader !== null) {
            $this->graphReader->addContribution($contribution);
        }
    }
}
