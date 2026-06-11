<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

final class ServiceLocationFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var list<ServiceLocationEdge> */
    private array $edges = [];

    public function __construct(
        private string $file,
    ) {}

    /**
     * @return list<ServiceLocationEdge>
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

        if ($this->currentClass === null) {
            return null;
        }

        if ($node instanceof FuncCall) {
            $edge = $this->edgeFromFunctionCall($node);
            if ($edge !== null) {
                $this->edges[] = $edge;
            }
        }

        if ($node instanceof StaticCall) {
            $edge = $this->edgeFromStaticCall($node);
            if ($edge !== null) {
                $this->edges[] = $edge;
            }
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

    private function edgeFromFunctionCall(FuncCall $node): ?ServiceLocationEdge
    {
        $via = $this->functionVia($node);
        if ($via === null) {
            return null;
        }

        return $this->buildEdge($node, $via);
    }

    private function edgeFromStaticCall(StaticCall $node): ?ServiceLocationEdge
    {
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'make') {
            return null;
        }

        if (! $this->isAppFacadeCall($node->class)) {
            return null;
        }

        return $this->buildEdge($node, 'App::make');
    }

    private function buildEdge(FuncCall|StaticCall $node, string $via): ?ServiceLocationEdge
    {
        $dependency = $this->resolveDependencyName($node->args[0]->value ?? null);
        if ($dependency === null || $this->currentClass === null) {
            return null;
        }

        return new ServiceLocationEdge(
            class: $this->currentClass,
            dependency: $dependency,
            via: $via,
            file: $this->file,
            line: $node->getStartLine(),
        );
    }

    private function functionVia(FuncCall $node): ?string
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        return match ($node->name->toString()) {
            'app' => 'app',
            'resolve' => 'resolve',
            default => null,
        };
    }

    private function isAppFacadeCall(Node $class): bool
    {
        if (! $class instanceof Name) {
            return false;
        }

        $name = $class->toString();

        return $name === 'App' || $name === 'Illuminate\\Support\\Facades\\App';
    }

    private function resolveDependencyName(?Node\Expr $argument): ?string
    {
        if ($argument instanceof ClassConstFetch && $argument->class instanceof Name) {
            return $this->qualifyName(ltrim($argument->class->toString(), '\\'));
        }

        if ($argument instanceof String_) {
            $value = $argument->value;

            return $value !== '' ? ltrim($value, '\\') : null;
        }

        return null;
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
