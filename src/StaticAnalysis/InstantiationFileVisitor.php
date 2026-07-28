<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeVisitorAbstract;

final class InstantiationFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var array<string, string> */
    private array $imports = [];

    /** @var list<InstantiationEdge> */
    private array $edges = [];

    public function __construct(
        private string $file,
        private InstantiationBuiltinFilter $builtinFilter,
    ) {}

    /**
     * @return list<InstantiationEdge>
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

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->imports[$use->getAlias()->toString()] = ltrim($use->name->toString(), '\\');
            }

            return null;
        }

        if ($node instanceof Class_) {
            $this->currentClass = $this->qualifyName($node->name?->toString() ?? '');

            return null;
        }

        if ($this->currentClass === null || ! $node instanceof New_) {
            return null;
        }

        $edge = $this->edgeFromNewExpression($node);
        if ($edge !== null) {
            $this->edges[] = $edge;
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Class_) {
            $this->currentClass = null;
        }

        return null;
    }

    private function edgeFromNewExpression(New_ $node): ?InstantiationEdge
    {
        if ($node->class instanceof Class_) {
            return null;
        }

        if (! $node->class instanceof Name) {
            return null;
        }

        if ($node->class->isSpecialClassName()) {
            return null;
        }

        $dependency = $this->resolveClassName($node->class);
        if ($this->builtinFilter->shouldSkip($dependency)) {
            return null;
        }

        return new InstantiationEdge(
            class: $this->currentClass,
            dependency: $dependency,
            file: $this->file,
            line: $node->getStartLine(),
        );
    }

    private function resolveClassName(Name $name): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $shortName = $name->getLast();
        if (isset($this->imports[$shortName])) {
            return $this->imports[$shortName];
        }

        return $this->qualifyName($name->toString());
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
