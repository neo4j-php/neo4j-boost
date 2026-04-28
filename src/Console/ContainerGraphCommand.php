<?php

namespace Neo4j\LaravelBoost\Console;

use Closure;
use Illuminate\Console\Command;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

class ContainerGraphCommand extends Command
{
    protected $signature = 'container:graph {--dry-run : Extract only, do not write to Neo4j} {--print-cypher : Print Cypher templates before running}';

    protected $description = 'Export Laravel container wiring into Neo4j for dependency debugging';

    public function handle(ContainerGraphWriter $writer): int
    {
        [$bindingRows, $concreteClasses] = $this->extractBindingRows();
        [$dependencyRows, $unresolvedRows] = $this->extractDependencyRows($concreteClasses);

        $this->line('Container graph summary:');
        $this->line('- Bindings: ' . count($bindingRows));
        $this->line('- Concrete classes inspected: ' . count($concreteClasses));
        $this->line('- Dependency edges: ' . count($dependencyRows));
        $this->line('- Unresolved dependencies: ' . count($unresolvedRows));

        if ($this->option('print-cypher')) {
            $this->printCypher($writer, $bindingRows, $dependencyRows, $unresolvedRows);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No data written to Neo4j.');

            return self::SUCCESS;
        }

        try {
            $writer->connect();
            $writer->write($bindingRows, $dependencyRows, $unresolvedRows);
        } catch (Throwable $e) {
            $this->error('Failed to write container graph: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Container graph written to Neo4j successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{abstract: string, abstractKind: string, concrete: string, shared: bool}>, 1: array<int, string>}
     */
    private function extractBindingRows(): array
    {
        /** @var array<string, array{concrete: mixed, shared: bool}> $bindings */
        $bindings = app()->getBindings();
        $rows = [];
        $concreteClasses = [];

        foreach ($bindings as $abstract => $binding) {
            $concreteName = $this->resolveConcreteName($binding['concrete'] ?? null);
            if ($concreteName === null || ! class_exists($concreteName)) {
                continue;
            }

            $rows[] = [
                'abstract' => $abstract,
                'abstractKind' => $this->kindForTypeName($abstract),
                'concrete' => $concreteName,
                'shared' => (bool) ($binding['shared'] ?? false),
            ];
            $concreteClasses[$concreteName] = $concreteName;
        }

        return [array_values($rows), array_values($concreteClasses)];
    }

    /**
     * @param array<int, string> $classes
     *
     * @return array{0: array<int, array{class: string, dependency: string, dependencyKind: string}>, 1: array<int, array{class: string, name: string, reason: string}>}
     */
    private function extractDependencyRows(array $classes): array
    {
        $dependencyRows = [];
        $unresolvedRows = [];

        foreach ($classes as $className) {
            try {
                $reflection = new ReflectionClass($className);
            } catch (Throwable) {
                continue;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                [$name, $kind, $reason] = $this->dependencyFromParameter($parameter);
                if ($name === null) {
                    continue;
                }

                if ($kind === 'UnresolvedDependency') {
                    $unresolvedRows[] = [
                        'class' => $className,
                        'name' => $name,
                        'reason' => $reason ?? 'unresolved',
                    ];
                } else {
                    $dependencyRows[] = [
                        'class' => $className,
                        'dependency' => $name,
                        'dependencyKind' => $kind,
                    ];
                }
            }
        }

        return [$this->uniqueRows($dependencyRows), $this->uniqueRows($unresolvedRows)];
    }

    private function resolveConcreteName(mixed $concrete): ?string
    {
        if (is_string($concrete)) {
            return $concrete;
        }

        if ($concrete instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($concrete);
                $static = $reflection->getStaticVariables();
                if (isset($static['concrete']) && is_string($static['concrete'])) {
                    return $static['concrete'];
                }
                if (isset($static['abstract']) && is_string($static['abstract'])) {
                    return $static['abstract'];
                }

                $returnType = $reflection->getReturnType();
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    return $returnType->getName();
                }
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private function dependencyFromParameter(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return [$parameter->getName(), 'UnresolvedDependency', 'missing_type_hint'];
        }

        if ($type instanceof ReflectionUnionType) {
            return [$parameter->getName(), 'UnresolvedDependency', 'union_type'];
        }

        if (! $type instanceof ReflectionNamedType) {
            return [$parameter->getName(), 'UnresolvedDependency', 'unsupported_type'];
        }

        if ($type->isBuiltin()) {
            return [$parameter->getName(), 'UnresolvedDependency', 'builtin_' . $type->getName()];
        }

        $name = $type->getName();

        return [$name, $this->kindForTypeName($name), null];
    }

    private function kindForTypeName(string $name): string
    {
        return interface_exists($name) ? 'Interface' : 'Class';
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param array<int, T> $rows
     * @return array<int, T>
     */
    private function uniqueRows(array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $key = json_encode($row);
            if ($key === false || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @param array<int, array{abstract: string, abstractKind: string, concrete: string, shared: bool}> $bindingRows
     * @param array<int, array{class: string, dependency: string, dependencyKind: string}> $dependencyRows
     * @param array<int, array{class: string, name: string, reason: string}> $unresolvedRows
     */
    private function printCypher(ContainerGraphWriter $writer, array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
        $this->line('');
        $this->line('Cypher templates:');
        foreach ($writer->cypherTemplates() as $label => $cypher) {
            $this->line('[' . $label . ']');
            $this->line($cypher);
            $this->line('');
        }

        $this->line('Sample params:');
        $this->line('- bindings: ' . json_encode(array_slice($bindingRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- dependencies: ' . json_encode(array_slice($dependencyRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- unresolved: ' . json_encode(array_slice($unresolvedRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }
}
