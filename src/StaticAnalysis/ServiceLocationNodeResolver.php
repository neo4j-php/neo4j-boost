<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;

final class ServiceLocationNodeResolver
{
    public function __construct(
        private ServiceLocationCallDetector $detector = new ServiceLocationCallDetector,
    ) {}

    public function fromFuncCall(FuncCall $node, Scope $scope): ?ServiceLocationEdge
    {
        $match = $this->detector->matchFuncCall($node);
        if ($match === null) {
            return null;
        }

        return $this->buildEdge($match['via'], $match['args'], $node->getStartLine(), $scope);
    }

    public function fromStaticCall(StaticCall $node, Scope $scope): ?ServiceLocationEdge
    {
        $match = $this->detector->matchStaticCall($node);
        if ($match === null) {
            return null;
        }

        return $this->buildEdge($match['via'], $match['args'], $node->getStartLine(), $scope);
    }

    public function fromMethodCall(MethodCall $node, Scope $scope): ?ServiceLocationEdge
    {
        $match = $this->detector->matchMethodCallWithScope($node, $scope);
        if ($match === null) {
            return null;
        }

        return $this->buildEdge($match['via'], $match['args'], $node->getStartLine(), $scope);
    }

    /**
     * @param  array<int, Node\Arg>  $args
     */
    private function buildEdge(string $via, array $args, int $line, Scope $scope): ?ServiceLocationEdge
    {
        $classReflection = $scope->getClassReflection();
        if ($classReflection === null) {
            return null;
        }

        $resolution = $this->detector->resolveFromArgs($args, $scope);
        if ($resolution === null) {
            return null;
        }

        return new ServiceLocationEdge(
            class: $classReflection->getName(),
            dependency: $resolution['dependency'],
            via: $via,
            file: $scope->getFile(),
            line: $line,
            resolved: $resolution['resolved'],
            reason: $resolution['reason'],
        );
    }
}
