<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\ResolutionCatalog\GlobalHelperCatalog;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

final class GlobalHelperFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var list<GlobalHelperEdge> */
    private array $edges = [];

    public function __construct(
        private string $file,
        private GlobalHelperCatalog $catalog,
    ) {}

    /**
     * @return list<GlobalHelperEdge>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name instanceof Name ? $node->name->toString() : '';

            return null;
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $this->qualifyName($node->name?->toString() ?? '');

            return null;
        }

        if ($this->currentClass === null || ! $node instanceof FuncCall) {
            return null;
        }

        $edge = $this->edgeFromFunctionCall($node);
        if ($edge !== null) {
            $this->edges[] = $edge;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        }

        return null;
    }

    private function edgeFromFunctionCall(FuncCall $node): ?GlobalHelperEdge
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        $helper = $node->name->toString();
        if (! $this->catalog->isTrackedHelper($helper)) {
            return null;
        }

        $literalKey = isset($node->args[0])
            ? $this->literalStringArgument($node->args[0]->value ?? null)
            : null;
        $resolution = $this->catalog->resolve($helper, $literalKey);
        if ($resolution === null || $this->currentClass === null) {
            return null;
        }

        return new GlobalHelperEdge(
            class: $this->currentClass,
            dependency: $resolution['abstract'],
            helper: $resolution['helper'],
            confidence: $resolution['confidence'],
            file: $this->file,
            line: $node->getStartLine(),
        );
    }

    private function literalStringArgument(?Node\Expr $argument): ?string
    {
        if (! $argument instanceof String_) {
            return null;
        }

        return $argument->value !== '' ? $argument->value : null;
    }

    private function qualifyName(string $shortName): string
    {
        if ($shortName === '') {
            return $this->namespace;
        }

        if (str_contains($shortName, '\\')) {
            return ltrim($shortName, '\\');
        }

        return $this->namespace === '' ? $shortName : $this->namespace.'\\'.$shortName;
    }
}
