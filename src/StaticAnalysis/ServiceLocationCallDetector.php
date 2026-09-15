<?php

namespace Neo4j\LaravelBoost\StaticAnalysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * Matches Laravel service-locator calls and resolves their first argument when static.
 */
final class ServiceLocationCallDetector
{
    public const UNRESOLVED_IDENTIFIER = '__dynamic__';

    public const UNRESOLVED_REASON = 'dynamic service locator argument';

    /**
     * Types that expose make() / makeWith() as container lookups.
     *
     * @var list<string>
     */
    private const CONTAINER_CLASS_NAMES = [
        'Illuminate\\Contracts\\Container\\Container',
        'Illuminate\\Container\\Container',
        'Illuminate\\Contracts\\Foundation\\Application',
        'Illuminate\\Foundation\\Application',
        'Application',
    ];

    /**
     * @return array{via: string, args: array<int, Node\Arg>}|null
     */
    public function matchFuncCall(FuncCall $node): ?array
    {
        if (! $node->name instanceof Name) {
            return null;
        }

        $via = match ($node->name->toString()) {
            'app' => 'app',
            'resolve' => 'resolve',
            default => null,
        };

        if ($via === null) {
            return null;
        }

        return ['via' => $via, 'args' => $node->args];
    }

    /**
     * @return array{via: string, args: array<int, Node\Arg>}|null
     */
    public function matchStaticCall(StaticCall $node, ?string $resolvedCalleeClass = null): ?array
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        if (! in_array($method, ['make', 'makeWith'], true)) {
            return null;
        }

        $className = $resolvedCalleeClass ?? $this->unresolvedClassName($node->class);
        if ($className === null) {
            return null;
        }

        if (AppFacadeClassChecker::is($className)) {
            return ['via' => 'App::'.$method, 'args' => $node->args];
        }

        if ($this->isContainerClassName($className)) {
            return ['via' => 'Application::'.$method, 'args' => $node->args];
        }

        return null;
    }

    /**
     * @param  null|string  $receiverTypeFqcn  Declared type of the receiver when known (PhpParser path).
     * @return array{via: string, args: array<int, Node\Arg>}|null
     */
    public function matchMethodCall(MethodCall $node, ?string $receiverTypeFqcn = null): ?array
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        if (! in_array($method, ['make', 'makeWith'], true)) {
            return null;
        }

        $receiverVia = $this->methodReceiverVia($node->var, $receiverTypeFqcn);
        if ($receiverVia === null) {
            return null;
        }

        return ['via' => $receiverVia.'->'.$method, 'args' => $node->args];
    }

    /**
     * @return array{via: string, args: array<int, Node\Arg>}|null
     */
    public function matchMethodCallWithScope(MethodCall $node, Scope $scope): ?array
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();
        if (! in_array($method, ['make', 'makeWith'], true)) {
            return null;
        }

        $nameVia = $this->methodReceiverVia($node->var, null);
        if ($nameVia === null && ! $this->isContainerReceiver($node->var, $scope)) {
            return null;
        }

        $via = ($nameVia ?? $this->receiverLabel($node->var) ?? '$app').'->'.$method;

        return ['via' => $via, 'args' => $node->args];
    }

    /**
     * @param  array<int, Node\Arg>  $args
     * @return array{dependency: string, resolved: bool, reason: ?string}|null
     */
    public function resolveFromArgs(array $args, Scope $scope): ?array
    {
        if ($args === []) {
            return null;
        }

        return $this->resolveFirstArgument($args[0]->value ?? null, $scope);
    }

    /**
     * @return array{dependency: string, resolved: bool, reason: ?string}|null
     */
    public function resolveFirstArgument(?Expr $argument, Scope $scope): ?array
    {
        if ($argument === null) {
            return null;
        }

        if ($argument instanceof ClassConstFetch && $argument->class instanceof Name) {
            $dependency = $this->resolveClassName($argument->class, $scope);

            return $dependency === ''
                ? null
                : ['dependency' => $dependency, 'resolved' => true, 'reason' => null];
        }

        if ($argument instanceof String_) {
            $value = $argument->value;

            return $value === ''
                ? null
                : ['dependency' => ltrim($value, '\\'), 'resolved' => true, 'reason' => null];
        }

        return [
            'dependency' => self::UNRESOLVED_IDENTIFIER,
            'resolved' => false,
            'reason' => self::UNRESOLVED_REASON,
        ];
    }

    public function isContainerClassName(string $className): bool
    {
        $className = ltrim($className, '\\');

        foreach (self::CONTAINER_CLASS_NAMES as $containerClass) {
            if ($className === $containerClass) {
                return true;
            }
        }

        foreach ([
            'Illuminate\\Contracts\\Container\\Container',
            'Illuminate\\Container\\Container',
            'Illuminate\\Contracts\\Foundation\\Application',
            'Illuminate\\Foundation\\Application',
        ] as $containerClass) {
            if (is_a($className, $containerClass, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveClassName(Name $name, Scope $scope): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        return ltrim($scope->resolveName($name), '\\');
    }

    private function unresolvedClassName(Node $class): ?string
    {
        if (! $class instanceof Name) {
            return null;
        }

        return ltrim($class->toString(), '\\');
    }

    /**
     * Match `$app` / `$this->app` by name (legacy), or any `$var` / `$this->prop`
     * whose declared type is Application/Container.
     */
    private function methodReceiverVia(Expr $receiver, ?string $receiverTypeFqcn): ?string
    {
        $label = $this->receiverLabel($receiver);
        if ($label === null) {
            return null;
        }

        $isLegacyAppName = $label === '$app' || $label === '$this->app';
        if ($isLegacyAppName) {
            return $label;
        }

        if ($receiverTypeFqcn !== null && $this->isContainerClassName($receiverTypeFqcn)) {
            return $label;
        }

        return null;
    }

    private function receiverLabel(Expr $receiver): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return '$'.$receiver->name;
        }

        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && is_string($receiver->var->name)
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier) {
            return '$this->'.$receiver->name->toString();
        }

        return null;
    }

    private function isContainerReceiver(Expr $receiver, Scope $scope): bool
    {
        if ($this->methodReceiverVia($receiver, null) !== null) {
            return true;
        }

        $type = $scope->getType($receiver);

        foreach (self::CONTAINER_CLASS_NAMES as $containerClass) {
            if ($containerClass === 'Application') {
                continue;
            }

            if ((new ObjectType($containerClass))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }
}
