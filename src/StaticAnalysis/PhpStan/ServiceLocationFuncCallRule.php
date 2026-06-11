<?php

namespace Neo4j\LaravelBoost\StaticAnalysis\PhpStan;

use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<FuncCall>
 */
final class ServiceLocationFuncCallRule implements Rule
{
    public function __construct(
        private ServiceLocationNodeResolver $resolver = new ServiceLocationNodeResolver,
    ) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof FuncCall) {
            return [];
        }

        $edge = $this->resolver->fromFuncCall($node, $scope);
        if ($edge !== null) {
            StaticAnalysisCollector::addServiceLocation($edge);
        }

        return [];
    }
}
