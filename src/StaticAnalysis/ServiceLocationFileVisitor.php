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
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

final class ServiceLocationFileVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private ?string $currentClass = null;

    /** @var array<string, string> */
    private array $imports = [];

    /**
     * Property name => FQCN for the current class (plus same-file parents).
     *
     * @var array<string, string>
     */
    private array $propertyTypes = [];

    /**
     * Local / parameter variable name => FQCN for the current method.
     *
     * @var array<string, string>
     */
    private array $variableTypes = [];

    /**
     * Class FQCN => property name => type FQCN (same-file inheritance).
     *
     * @var array<string, array<string, string>>
     */
    private array $classPropertyTypes = [];

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

        if ($node instanceof Class_) {
            $this->currentClass = $this->qualifyName($node->name?->toString() ?? '');
            $this->propertyTypes = $this->inheritedPropertyTypes($node);
            $this->collectClassPropertyTypes($node);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->variableTypes = [];
            foreach ($node->params as $param) {
                $this->registerParamType($param);
            }

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
            $receiverType = $this->resolveReceiverType($node->var);
            $this->recordEdge($this->detector->matchMethodCall($node, $receiverType), $node->getStartLine());
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof ClassMethod) {
            $this->variableTypes = [];
        }

        if ($node instanceof Class_) {
            if ($this->currentClass !== null) {
                $this->classPropertyTypes[$this->currentClass] = $this->propertyTypes;
            }
            $this->currentClass = null;
            $this->propertyTypes = [];
            $this->variableTypes = [];
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

    private function resolveReceiverType(Expr $receiver): ?string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return $this->variableTypes[$receiver->name] ?? null;
        }

        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && is_string($receiver->var->name)
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier) {
            return $this->propertyTypes[$receiver->name->toString()] ?? null;
        }

        return null;
    }

    private function collectClassPropertyTypes(Class_ $class): void
    {
        foreach ($class->getProperties() as $property) {
            $this->registerPropertyType($property);
        }

        $constructor = $class->getMethod('__construct');
        if ($constructor instanceof ClassMethod) {
            foreach ($constructor->params as $param) {
                if ($param->flags !== 0) {
                    $this->registerParamType($param, asProperty: true);
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function inheritedPropertyTypes(Class_ $class): array
    {
        if (! $class->extends instanceof Name) {
            return [];
        }

        $parent = $this->resolveClassName($class->extends);

        return $this->classPropertyTypes[$parent] ?? [];
    }

    private function registerPropertyType(Property $property): void
    {
        $typeName = $this->typeNameFromNode($property->type);
        if ($typeName === null) {
            return;
        }

        foreach ($property->props as $prop) {
            $this->propertyTypes[$prop->name->toString()] = $typeName;
        }
    }

    private function registerParamType(Param $param, bool $asProperty = false): void
    {
        if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
            return;
        }

        $typeName = $this->typeNameFromNode($param->type);
        if ($typeName === null) {
            return;
        }

        $name = $param->var->name;
        $this->variableTypes[$name] = $typeName;

        if ($asProperty || $param->flags !== 0) {
            $this->propertyTypes[$name] = $typeName;
        }
    }

    private function typeNameFromNode(mixed $type): ?string
    {
        if ($type instanceof Name) {
            return $this->resolveClassName($type);
        }

        if ($type instanceof NullableType) {
            return $this->typeNameFromNode($type->type);
        }

        if ($type instanceof UnionType) {
            foreach ($type->types as $unionType) {
                $name = $this->typeNameFromNode($unionType);
                if ($name !== null && $this->detector->isContainerClassName($name)) {
                    return $name;
                }
            }

            foreach ($type->types as $unionType) {
                $name = $this->typeNameFromNode($unionType);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
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

        $parts = $name->getParts();
        if (count($parts) > 1) {
            $first = $parts[0];
            if (isset($this->imports[$first])) {
                return $this->imports[$first].'\\'.implode('\\', array_slice($parts, 1));
            }
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
