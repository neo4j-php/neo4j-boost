<?php

namespace Neo4j\LaravelBoost;

use Illuminate\Support\Facades\DB;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Support\Graph\RuntimeGraphModel;
use Neo4j\LaravelBoost\Support\Neo4jBoltClient;
use Neo4j\Neo4jLaravel\Neo4jQueryBuilder;
use RuntimeException;

class GraphKnowledgeContributor
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_USER = 'user';

    public const RELATIONSHIP_DEPENDS_ON = 'DEPENDS_ON';

    public const RELATIONSHIP_BINDS_TO = 'BINDS_TO';

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
        if ($relationship === self::RELATIONSHIP_DEPENDS_ON) {
            $type = DependsOnType::assertAllowed($dependsOnType ?? DependsOnType::ServiceLocation->value)->value;
            $fromKind = 'Class';
            $toKind = $this->kindForTypeName($to) === 'Interface' ? 'Interface' : 'Class';
        } else {
            $relationship = self::RELATIONSHIP_BINDS_TO;
            $type = BindsToType::fromShared($shared ?? false)->value;
            $fromKind = $this->kindForTypeName($from);
            $toKind = $this->kindForTypeName($to);
        }

        $abstracts = $this->abstracts();
        $abstracts->upsert(['name' => $from, 'kind' => $fromKind], [RuntimeGraphModel::NAME_KEY]);
        $abstracts->upsert(['name' => $to, 'kind' => $toKind], [RuntimeGraphModel::NAME_KEY]);
        $abstracts->upsertRelationships($relationship.'>', RuntimeGraphModel::LABEL_ABSTRACT, [[
            'from' => ['name' => $from],
            'to' => ['name' => $to],
            'properties' => [
                'type' => $type,
                'source' => $source,
                'confidence' => $confidence,
                'reason' => $reason ?? '',
            ],
        ]]);
    }

    private function abstracts(): Neo4jQueryBuilder
    {
        $query = DB::connection(Neo4jBoltClient::ELOQUENT_CONNECTION)->table(RuntimeGraphModel::LABEL_ABSTRACT);

        if (! $query instanceof Neo4jQueryBuilder) {
            throw new RuntimeException('The '.Neo4jBoltClient::ELOQUENT_CONNECTION.' connection must use the neo4j-laravel query builder.');
        }

        return $query;
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
