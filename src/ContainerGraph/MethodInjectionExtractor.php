<?php

namespace Neo4j\LaravelBoost\ContainerGraph;

use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use ReflectionClass;
use Throwable;

/**
 * Extracts method-injection dependencies from Laravel entry-point classes.
 */
final class MethodInjectionExtractor
{
    public function __construct(
        private MethodInjectionTargetResolver $targetResolver,
        private ParameterDependencyResolver $parameterResolver,
    ) {}

    /**
     * @param  array<int, string>  $classes
     * @return array{
     *     0: array<int, array{
     *         class: string,
     *         dependency: string,
     *         dependencyKind: string,
     *         type: string,
     *         method: string,
     *         parameter: string,
     *         source: string,
     *         via: string,
     *         file: string,
     *         line: int
     *     }>,
     *     1: array<int, array{class: string, name: string, reason: string, type: string, method: string, parameter: string}>
     * }
     */
    public function extract(array $classes): array
    {
        $dependencyRows = [];
        $unresolvedRows = [];

        foreach ($classes as $className) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            foreach ($this->targetResolver->methodsForClass($reflection) as $methodName) {
                $method = $reflection->getMethod($methodName);
                $file = (string) ($method->getFileName() ?: '');
                $line = (int) $method->getStartLine();

                foreach ($method->getParameters() as $parameter) {
                    [$name, $kind] = $this->parameterResolver->resolve($parameter);
                    if ($name === null) {
                        continue;
                    }

                    $parameterName = $parameter->getName();

                    if ($kind === 'UnresolvedDependency') {
                        $unresolvedRows[] = [
                            'class' => $className,
                            'name' => $name,
                            'reason' => 'unresolved',
                            'type' => DependsOnType::MethodInjection->value,
                            'method' => $methodName,
                            'parameter' => $parameterName,
                        ];

                        continue;
                    }

                    $dependencyRows[] = [
                        'class' => $className,
                        'dependency' => $name,
                        'dependencyKind' => $kind,
                        'type' => DependsOnType::MethodInjection->value,
                        'method' => $methodName,
                        'parameter' => $parameterName,
                        'source' => '',
                        'via' => '',
                        'file' => $file,
                        'line' => $line,
                    ];
                }
            }
        }

        return [$dependencyRows, $unresolvedRows];
    }
}
