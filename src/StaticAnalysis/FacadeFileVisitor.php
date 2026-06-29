<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeVisitorAbstract;

final class FacadeFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var array<string, string> */
    private array $imports = [];

    /** @var list<FacadeEdge> */
    private array $edges = [];

    public function __construct(
        private string $file,
        private ResolutionCatalog $catalog,
    ) {}

    /**
     * @return list<FacadeEdge>
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

        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $this->qualifyName($node->name?->toString() ?? '');

            return null;
        }

        if ($this->currentClass === null || ! $node instanceof StaticCall) {
            return null;
        }

        $edge = $this->edgeFromStaticCall($node);
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

    private function edgeFromStaticCall(StaticCall $node): ?FacadeEdge
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        $facadeClass = $this->resolveFacadeClass($node->class);
        if ($facadeClass === null) {
            return null;
        }

        if ($this->isServiceLocationAppMake($facadeClass, $method)) {
            return null;
        }

        $entry = $this->catalog->resolveFacade($facadeClass);
        if ($entry === null) {
            return null;
        }

        return new FacadeEdge(
            class: $this->currentClass,
            dependency: $entry->abstract,
            facadeClass: $facadeClass,
            method: $method,
            file: $this->file,
            line: $node->getStartLine(),
        );
    }

    private function resolveFacadeClass(Node $class): ?string
    {
        if (! $class instanceof Name) {
            return null;
        }

        if ($class->isFullyQualified()) {
            return ltrim($class->toString(), '\\');
        }

        $shortName = $class->getLast();
        if (isset($this->imports[$shortName])) {
            return $this->imports[$shortName];
        }

        return $this->qualifyName($class->toString());
    }

    private function isServiceLocationAppMake(string $facadeClass, string $method): bool
    {
        return in_array($method, ['make', 'makeWith'], true)
            && AppFacadeClassChecker::is($facadeClass);
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
