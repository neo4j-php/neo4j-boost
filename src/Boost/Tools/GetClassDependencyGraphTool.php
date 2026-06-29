<?php

namespace Neo4j\LaravelBoost\Boost\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Neo4j\LaravelBoost\ClassDependencyGraphReader;
use Neo4j\LaravelBoost\Support\Graph\GraphRelationshipGlossary;
use Throwable;

#[IsReadOnly]
final class GetClassDependencyGraphTool extends Tool
{
    protected string $name = 'get-class-dependency-graph';

    protected string $description = 'Returns the Laravel container dependency graph for a fully-qualified PHP class: declared and hidden dependencies (with type, source, confidence, visibility), binding targets, dependents, unresolved types, and graph_completeness metadata. Requires php artisan container:graph to have exported data to Neo4j. Use when exploring architecture, DI wiring, or "what depends on / is injected into X?".'.GraphRelationshipGlossary::MCP_TOOL_DESCRIPTION_SUFFIX;

    public function __construct(
        private ClassDependencyGraphReader $reader,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'class' => $schema->string()
                ->description('Fully-qualified PHP class name, e.g. App\\Services\\FooService')
                ->required(),
            'depth' => $schema->integer()
                ->description('Max hops for DEPENDS_ON traversal (1-10)')
                ->min(1)
                ->max(10)
                ->default(4),
            'direction' => $schema->string()
                ->description('Traversal direction: outbound (dependencies), inbound (dependents), or both')
                ->enum(['outbound', 'inbound', 'both'])
                ->default('outbound'),
            'include_bindings' => $schema->boolean()
                ->description('Include BINDS_TO when the class is a binding key or resolved target')
                ->default(true),
            'page' => $schema->integer()
                ->description('Page number for paginated dependencies/dependents (1-based)')
                ->min(1)
                ->default(1),
            'per_page' => $schema->integer()
                ->description('Maximum dependency/dependent entries per page (default 100)')
                ->min(1)
                ->max(ClassDependencyGraphReader::MAX_PER_PAGE)
                ->default(ClassDependencyGraphReader::DEFAULT_PER_PAGE),
        ];
    }

    public function handle(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'class' => 'required|string|min:1',
            'depth' => 'sometimes|integer|min:1|max:10',
            'direction' => 'sometimes|string|in:outbound,inbound,both',
            'include_bindings' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:'.ClassDependencyGraphReader::MAX_PER_PAGE,
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        try {
            $graph = $this->reader->getGraph(
                class: $validated['class'],
                depth: (int) ($validated['depth'] ?? 4),
                direction: (string) ($validated['direction'] ?? 'outbound'),
                includeBindings: (bool) ($validated['include_bindings'] ?? true),
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? ClassDependencyGraphReader::DEFAULT_PER_PAGE),
            );
        } catch (Throwable $e) {
            return Response::error('Failed to read container graph: '.$e->getMessage());
        }

        return Response::json($graph);
    }
}
