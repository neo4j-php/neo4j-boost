<?php

namespace Neo4j\LaravelBoost\StaticAnalysis\PhpStan;

use Neo4j\LaravelBoost\StaticAnalysis\ServiceLocationNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class ServiceLocationStaticCallRule implements Rule
{
    public function __construct(
        private ServiceLocationNodeResolver $resolver,
    ) {}

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof StaticCall) {
            return [];
        }

        $edge = $this->resolver->fromStaticCall($node, $scope);
        if ($edge !== null) {
            StaticAnalysisCollector::addServiceLocation($edge);
        }

        return [];
    }
}
