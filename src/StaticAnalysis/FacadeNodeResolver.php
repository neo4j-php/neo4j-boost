<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use Illuminate\Support\Facades\App;
use Neo4j\LaravelBoost\ResolutionCatalog\ResolutionCatalog;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;

final class FacadeNodeResolver
{
    public function __construct(
        private ResolutionCatalog $catalog,
    ) {}

    public function fromStaticCall(StaticCall $node, Scope $scope): ?FacadeEdge
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        $facadeClass = $this->resolveFacadeClass($node->class, $scope);
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

        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return null;
        }

        return new FacadeEdge(
            class: $classReflection->getName(),
            dependency: $entry->abstract,
            facadeClass: $facadeClass,
            method: $method,
            file: $scope->getFile(),
            line: $node->getStartLine(),
        );
    }

    private function resolveFacadeClass(Node $class, Scope $scope): ?string
    {
        if ($class instanceof Name) {
            return ltrim($scope->resolveName($class), '\\');
        }

        if (! $class instanceof Expr) {
            return null;
        }

        $type = $scope->getType($class);
        if ($type->isObject()->yes()) {
            $classNames = $type->getObjectClassNames();

            return $classNames[0] ?? null;
        }

        return null;
    }

    private function isServiceLocationAppMake(string $facadeClass, string $method): bool
    {
        return $method === 'make'
            && ($facadeClass === App::class || $facadeClass === 'App');
    }
}
