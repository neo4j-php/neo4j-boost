<?php

namespace Neo4j\LaravelBoost\StaticAnalysis\PhpStan;

use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<MethodCall>
 */
final class ServiceLocationMethodCallRule implements Rule
{
    public function __construct(
        private ServiceLocationNodeResolver $resolver,
    ) {}

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall) {
            return [];
        }

        $edge = $this->resolver->fromMethodCall($node, $scope);
        if ($edge !== null) {
            StaticAnalysisCollector::addServiceLocation($edge);
        }

        return [];
    }
}
