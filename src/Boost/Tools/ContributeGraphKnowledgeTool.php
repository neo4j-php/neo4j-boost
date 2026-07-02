<?php

namespace Neo4j\LaravelBoost\Boost\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Neo4j\LaravelBoost\GraphKnowledgeContributor;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use Neo4j\LaravelBoost\Support\Graph\GraphRelationshipGlossary;
use Throwable;

final class ContributeGraphKnowledgeTool extends Tool
{
    protected string $name = 'contribute-graph-knowledge';

    protected string $description = 'Contribute dependency or binding knowledge to the Laravel container graph when static analysis cannot infer it. Medium/low confidence returns confirmation_required without writing; ask the user, then retry with confirmed=true to persist with source=user. High confidence persists immediately with source=agent. Requires php artisan container:graph to have exported data to Neo4j.'.GraphRelationshipGlossary::MCP_TOOL_DESCRIPTION_SUFFIX;

    public function __construct(
        private GraphKnowledgeContributor $contributor,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'relationship' => $schema->string()
                ->description('Graph relationship to add: DEPENDS_ON (constructor dependency) or BINDS_TO (container binding)')
                ->enum([
                    GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON,
                    GraphKnowledgeContributor::RELATIONSHIP_BINDS_TO,
                ])
                ->required(),
            'from' => $schema->string()
                ->description('Source fully-qualified PHP class or interface name')
                ->required(),
            'to' => $schema->string()
                ->description('Target fully-qualified PHP class or interface name')
                ->required(),
            'confidence' => $schema->string()
                ->description('Agent confidence: high persists immediately; medium/low require user confirmation')
                ->enum([
                    GraphKnowledgeContributor::CONFIDENCE_HIGH,
                    GraphKnowledgeContributor::CONFIDENCE_MEDIUM,
                    GraphKnowledgeContributor::CONFIDENCE_LOW,
                ])
                ->required(),
            'confirmed' => $schema->boolean()
                ->description('Set true after the user confirms a medium/low confidence proposal')
                ->default(false),
            'reason' => $schema->string()
                ->description('Optional explanation for why this edge should exist'),
            'shared' => $schema->boolean()
                ->description('For BINDS_TO only: whether the binding is shared (singleton)')
                ->default(false),
            'depends_on_type' => $schema->string()
                ->description('For DEPENDS_ON only: relationship type (constructor_injection, method_injection, facade, global_helper, service_location, instantiation). Defaults to service_location for agent contributions.')
                ->enum(DependsOnType::values()),
        ];
    }

    public function handle(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'relationship' => 'required|string|in:'.GraphKnowledgeContributor::RELATIONSHIP_DEPENDS_ON.','.GraphKnowledgeContributor::RELATIONSHIP_BINDS_TO,
            'from' => 'required|string|min:1',
            'to' => 'required|string|min:1',
            'confidence' => 'required|string|in:'.GraphKnowledgeContributor::CONFIDENCE_HIGH.','.GraphKnowledgeContributor::CONFIDENCE_MEDIUM.','.GraphKnowledgeContributor::CONFIDENCE_LOW,
            'confirmed' => 'sometimes|boolean',
            'reason' => 'sometimes|nullable|string',
            'shared' => 'sometimes|boolean',
            'depends_on_type' => 'sometimes|string|in:'.implode(',', DependsOnType::values()),
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        try {
            $result = $this->contributor->contribute(
                relationship: (string) $validated['relationship'],
                from: (string) $validated['from'],
                to: (string) $validated['to'],
                confidence: (string) $validated['confidence'],
                confirmed: (bool) ($validated['confirmed'] ?? false),
                reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
                shared: isset($validated['shared']) ? (bool) $validated['shared'] : null,
                dependsOnType: isset($validated['depends_on_type']) ? (string) $validated['depends_on_type'] : null,
            );
        } catch (Throwable $e) {
            return Response::error('Failed to contribute graph knowledge: '.$e->getMessage());
        }

        return Response::json($result);
    }
}
