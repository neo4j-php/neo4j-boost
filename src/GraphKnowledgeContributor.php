<?php

namespace Neo4j\LaravelBoost;

use Neo4j\LaravelBoost\Support\ContainerGraphConnection;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;

class GraphKnowledgeContributor
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_USER = 'user';

    public const RELATIONSHIP_DEPENDS_ON = 'DEPENDS_ON';

    public const RELATIONSHIP_BINDS_TO = 'BINDS_TO';

    private const CYPHER_DEPENDS_ON = <<<'CYPHER'
MERGE (c:Abstract {name: $from})
SET c.kind = 'Class'
REMOVE c:Interface, c:AbstractType
SET c:Class
WITH c
FOREACH (_ IN CASE WHEN $toKind = 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Abstract {name: $to})
  SET d.kind = 'Interface'
  REMOVE d:Class, d:AbstractType
  SET d:Interface
  MERGE (c)-[r:DEPENDS_ON]->(d)
  SET r.type = $type, r.source = $source, r.confidence = $confidence, r.reason = $reason
)
FOREACH (_ IN CASE WHEN $toKind <> 'Interface' THEN [1] ELSE [] END |
  MERGE (d:Abstract {name: $to})
  SET d.kind = 'Class'
  REMOVE d:Interface, d:AbstractType
  SET d:Class
  MERGE (c)-[r:DEPENDS_ON]->(d)
  SET r.type = $type, r.source = $source, r.confidence = $confidence, r.reason = $reason
)
CYPHER;

    private const CYPHER_BINDS_TO = <<<'CYPHER'
MERGE (a:Abstract {name: $from})
SET a.kind = $fromKind
REMOVE a:Interface, a:Class, a:AbstractType
FOREACH (_ IN CASE WHEN $fromKind = 'Interface' THEN [1] ELSE [] END | SET a:Interface)
FOREACH (_ IN CASE WHEN $fromKind = 'Class' THEN [1] ELSE [] END | SET a:Class)
FOREACH (_ IN CASE WHEN $fromKind <> 'Interface' AND $fromKind <> 'Class' THEN [1] ELSE [] END | SET a:AbstractType)
WITH a
MERGE (c:Abstract {name: $to})
SET c.kind = $toKind
REMOVE c:Interface, c:Class, c:AbstractType
FOREACH (_ IN CASE WHEN $toKind = 'Interface' THEN [1] ELSE [] END | SET c:Interface)
FOREACH (_ IN CASE WHEN $toKind = 'Class' THEN [1] ELSE [] END | SET c:Class)
FOREACH (_ IN CASE WHEN $toKind <> 'Interface' AND $toKind <> 'Class' THEN [1] ELSE [] END | SET c:AbstractType)
MERGE (a)-[r:BINDS_TO]->(c)
SET r.type = $type, r.source = $source, r.confidence = $confidence, r.reason = $reason
CYPHER;

    public function __construct(
        private ContainerGraphConnection $connection,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function contribute(
        string $relationship,
        string $from,
        string $to,
        string $confidence,
        bool $confirmed = false,
        ?string $reason = null,
        ?bool $shared = null,
        ?string $dependsOnType = null,
    ): array {
        $proposal = [
            'relationship' => $relationship,
            'from' => $from,
            'to' => $to,
            'confidence' => $confidence,
            'reason' => $reason,
        ];

        if ($relationship === self::RELATIONSHIP_DEPENDS_ON) {
            $proposal['type'] = $dependsOnType ?? DependsOnType::ServiceLocation->value;
        }

        if ($relationship === self::RELATIONSHIP_BINDS_TO) {
            $shared = $shared ?? false;
            $proposal['shared'] = $shared;
            $proposal['type'] = BindsToType::fromShared($shared)->value;
        }

        if ($this->requiresConfirmation($confidence) && ! $confirmed) {
            return [
                'status' => 'confirmation_required',
                'message' => 'User confirmation required before persisting this graph knowledge. Ask the user, then call again with confirmed=true.',
                'proposal' => $proposal,
            ];
        }

        $source = $confirmed ? self::SOURCE_USER : self::SOURCE_AGENT;

        $this->persist(
            relationship: $relationship,
            from: $from,
            to: $to,
            confidence: $confidence,
            source: $source,
            reason: $reason,
            shared: $shared,
            dependsOnType: $dependsOnType,
        );

        return [
            'status' => 'persisted',
            'source' => $source,
            ...$proposal,
        ];
    }

    private function requiresConfirmation(string $confidence): bool
    {
        return in_array($confidence, [self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW], true);
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
        $reason = $reason ?? '';

        if ($relationship === self::RELATIONSHIP_DEPENDS_ON) {
            $type = DependsOnType::assertAllowed($dependsOnType ?? DependsOnType::ServiceLocation->value);

            $this->connection->run(self::CYPHER_DEPENDS_ON, [
                'from' => $from,
                'to' => $to,
                'toKind' => $this->kindForTypeName($to),
                'type' => $type->value,
                'source' => $source,
                'confidence' => $confidence,
                'reason' => $reason,
            ]);

            return;
        }

        $bindsToType = BindsToType::fromShared($shared ?? false);

        $this->connection->run(self::CYPHER_BINDS_TO, [
            'from' => $from,
            'to' => $to,
            'fromKind' => $this->kindForTypeName($from),
            'toKind' => $this->kindForTypeName($to),
            'type' => $bindsToType->value,
            'source' => $source,
            'confidence' => $confidence,
            'reason' => $reason,
        ]);
    }

    private function kindForTypeName(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }
}
