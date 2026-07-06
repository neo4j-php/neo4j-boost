<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeVisitorAbstract;

final class ServiceLocationFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var array<string, string> */
    private array $imports = [];

    /** @var list<ServiceLocationEdge> */
    private array $edges = [];

    public function __construct(
        private string $file,
        private ServiceLocationCallDetector $detector = new ServiceLocationCallDetector,
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

        if ($this->currentClass === null) {
            return null;
        }

        if ($node instanceof FuncCall) {
            $this->recordEdge($this->detector->matchFuncCall($node), $node->getStartLine());
        }

        if ($node instanceof StaticCall) {
            $resolvedClass = $this->resolveStaticCallClass($node->class);
            $this->recordEdge($this->detector->matchStaticCall($node, $resolvedClass), $node->getStartLine());
        }

        if ($node instanceof MethodCall) {
            $this->recordEdge($this->detector->matchMethodCall($node), $node->getStartLine());
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

    /**
     * @param  array{via: string, args: array<int, Node\Arg>}|null  $match
     */
    private function recordEdge(?array $match, int $line): void
    {
        if ($match === null || $this->currentClass === null) {
            return;
        }

        $resolution = $this->resolveArgs($match['args']);
        if ($resolution === null) {
            return;
        }

        $this->edges[] = new ServiceLocationEdge(
            class: $this->currentClass,
            dependency: $resolution['dependency'],
            via: $match['via'],
            file: $this->file,
            line: $line,
            resolved: $resolution['resolved'],
            reason: $resolution['reason'],
        );
    }

    /**
     * @param  array<int, Node\Arg>  $args
     * @return array{dependency: string, resolved: bool, reason: ?string}|null
     */
    private function resolveArgs(array $args): ?array
    {
        if ($args === []) {
            return null;
        }

        $argument = $args[0]->value ?? null;
        if ($argument === null) {
            return null;
        }

        if ($argument instanceof ClassConstFetch && $argument->class instanceof Name) {
            $dependency = $this->resolveClassName($argument->class);

            return $dependency === '' ? null : ['dependency' => $dependency, 'resolved' => true, 'reason' => null];
        }

        if ($argument instanceof String_) {
            $value = $argument->value;

            return $value === '' ? null : ['dependency' => ltrim($value, '\\'), 'resolved' => true, 'reason' => null];
        }

        return [
            'dependency' => ServiceLocationCallDetector::UNRESOLVED_IDENTIFIER,
            'resolved' => false,
            'reason' => ServiceLocationCallDetector::UNRESOLVED_REASON,
        ];
    }

    private function resolveClassName(Name $name): string
    {
        $shortName = ltrim($name->toString(), '\\');

        if ($name->isFullyQualified()) {
            return $shortName;
        }

        if (isset($this->imports[$shortName])) {
            return $this->imports[$shortName];
        }

        return $this->qualifyName($shortName);
    }

    private function resolveStaticCallClass(Node $class): ?string
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
