<?php

namespace Neo4j\LaravelBoost\StaticAnalysis\PhpStan;

use Neo4j\LaravelBoost\StaticAnalysis\FacadeNodeResolver;
use Neo4j\LaravelBoost\StaticAnalysis\StaticAnalysisCollector;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;

/**
 * @implements Rule<StaticCall>
 */
final class FacadeStaticCallRule implements Rule
{
    public function __construct(
        private FacadeNodeResolver $resolver,
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
            StaticAnalysisCollector::addFacade($edge);
        }

        return [];
    }
}
