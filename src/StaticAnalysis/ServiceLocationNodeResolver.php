<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;

final class ServiceLocationNodeResolver
{
    public function fromFuncCall(FuncCall $node, Scope $scope): ?ServiceLocationEdge
    {
        $via = $this->functionVia($node);
        if ($via === null) {
            return null;
        }

        return $this->buildEdge($node, $scope, $via);
    }

    public function fromStaticCall(StaticCall $node, Scope $scope): ?ServiceLocationEdge
    {
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'make') {
            return null;
        }

        if (! $this->isAppFacadeCall($node->class)) {
            return null;
        }

        return $this->buildEdge($node, $scope, 'App::make');
    }

    private function buildEdge(Node $node, Scope $scope, string $via): ?ServiceLocationEdge
    {
        $classReflection = $scope->getClassReflection();
        $file = $scope->getFile();

        if ($classReflection === null) {
            return null;
        }

        $args = $node instanceof FuncCall || $node instanceof StaticCall ? $node->args : [];
        $firstArg = $args[0]->value ?? null;
        $dependency = $this->resolveDependencyName($firstArg, $scope);

        if ($dependency === null) {
            return null;
        }

        return new ServiceLocationEdge(
            class: $classReflection->getName(),
            dependency: $dependency,
            via: $via,
            file: $file,
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

    private function resolveDependencyName(?Node\Expr $argument, Scope $scope): ?string
    {
        if ($argument instanceof ClassConstFetch && $argument->class instanceof Name) {
            return $this->resolveClassName($argument->class, $scope);
        }

        if ($argument instanceof String_) {
            $value = $argument->value;

            return $value !== '' ? ltrim($value, '\\') : null;
        }

        return null;
    }

    private function resolveClassName(Name $name, Scope $scope): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $resolved = $scope->resolveName($name);

        return ltrim($resolved, '\\');
    }
}
