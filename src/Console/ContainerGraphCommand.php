<?php

namespace Neo4j\LaravelBoost\Console;

use Closure;
use Illuminate\Console\Command;
use Neo4j\LaravelBoost\ContainerGraphWriter;
use Neo4j\LaravelBoost\Support\Graph\BindsToType;
use Neo4j\LaravelBoost\Support\Graph\DependencySource;
use Neo4j\LaravelBoost\Support\Graph\DependsOnType;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
        $concreteClasses = $this->mergeClassLists($concreteClasses, $this->extractCustomClassNames());
        [$dependencyRows, $unresolvedRows] = $this->extractDependencyRows($concreteClasses);
        $classRows = array_map(
            static fn (string $className): array => ['class' => $className],
            $concreteClasses
        );

        $this->line('Container graph summary:');
        $this->line('- Bindings: '.count($bindingRows));
        $this->line('- Concrete classes inspected: '.count($concreteClasses));
        $this->line('- Class nodes: '.count($classRows));
        $this->line('- Dependency edges: '.count($dependencyRows));
        $this->line('- Unresolved dependencies: '.count($unresolvedRows));

        if ($this->option('print-cypher')) {
            $this->printCypher($writer, $classRows, $bindingRows, $dependencyRows, $unresolvedRows);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No data written to Neo4j.');

            return self::SUCCESS;
        }

        try {
            $writer->connect();
            $writer->write($classRows, $bindingRows, $dependencyRows, $unresolvedRows);
        } catch (Throwable $e) {
            $this->error('Failed to write container graph: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Container graph written to Neo4j successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string, source: string}>, 1: array<int, string>}
     */
    private function extractBindingRows(): array
    {
        /** @var array<string, array{concrete: mixed, shared: bool}> $bindings */
        $bindings = app()->getBindings();
        $rows = [];
        $concreteClasses = [];

        foreach ($bindings as $abstract => $binding) {
            $resolved = $this->resolveConcreteDescriptor($abstract, $binding['concrete'] ?? null);
            if ($resolved === null) {
                continue;
            }

            $shared = (bool) ($binding['shared'] ?? false);

            $rows[] = [
                'abstract' => $abstract,
                'abstractKind' => $this->kindForTypeName($abstract),
                'concrete' => $resolved['name'],
                'concreteKind' => $resolved['kind'],
                'shared' => $shared,
                'type' => BindsToType::fromShared($shared)->value,
                'source' => DependencySource::StaticAnalysis->value,
            ];

            if ($resolved['kind'] === 'Class' && class_exists($resolved['name']) && ! interface_exists($resolved['name'])) {
                $concreteClasses[$resolved['name']] = $resolved['name'];
            }
        }

        return [$rows, array_values($concreteClasses)];
    }

    /**
     * @return array<int, string>
     */
    private function extractCustomClassNames(): array
    {
        $composerJson = base_path('composer.json');
        if (! is_file($composerJson)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($composerJson), true);
        if (! is_array($decoded)) {
            return [];
        }

        // Production autoload only: autoload-dev often maps tests/ and Pest bootstrap
        // files (e.g. Tests\Pest) that fatal when class_exists() runs outside ./vendor/bin/pest.
        $autoload = $decoded['autoload']['psr-4'] ?? [];

        $classes = [];

        foreach ($autoload as $namespacePrefix => $paths) {
            if (! is_string($namespacePrefix)) {
                continue;
            }

            $pathList = is_array($paths) ? $paths : [$paths];
            foreach ($pathList as $path) {
                if (! is_string($path) || str_starts_with($path, 'vendor/')) {
                    continue;
                }

                $baseDir = base_path(trim($path, '/'));
                if (! is_dir($baseDir)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS)
                );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || $file->getExtension() !== 'php') {
                        continue;
                    }

                    $relativePath = ltrim(str_replace($baseDir, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                    $classSuffix = str_replace(
                        [DIRECTORY_SEPARATOR, '.php'],
                        ['\\', ''],
                        $relativePath
                    );
                    $className = rtrim($namespacePrefix, '\\').'\\'.$classSuffix;

                    if (class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
                        $classes[$className] = $className;
                    }
                }
            }
        }

        return array_values($classes);
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     * @return array<int, string>
     */
    private function mergeClassLists(array $left, array $right): array
    {
        $merged = [];

        foreach ([$left, $right] as $list) {
            foreach ($list as $className) {
                if (! is_string($className) || $className === '') {
                    continue;
                }
                $merged[$className] = $className;
            }
        }

        return array_values($merged);
    }

    /**
     * @param  array<int, string>  $classes
     * @return array{0: array<int, array{class: string, dependency: string, dependencyKind: string, type: string, source: string}>, 1: array<int, array{class: string, name: string, reason: string, type: string, source: string}>}
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
                        'type' => DependsOnType::ConstructorInjection->value,
                        'source' => DependencySource::StaticAnalysis->value,
                    ];
                } else {
                    $dependencyRows[] = [
                        'class' => $className,
                        'dependency' => $name,
                        'dependencyKind' => $kind,
                        'type' => DependsOnType::ConstructorInjection->value,
                        'source' => DependencySource::StaticAnalysis->value,
                    ];
                }
            }
        }

        return [$this->uniqueRows($dependencyRows), $this->uniqueRows($unresolvedRows)];
    }

    /**
     * @return null|array{name: string, kind: string}
     */
    private function resolveConcreteDescriptor(string $abstract, mixed $concrete): ?array
    {
        if (is_string($concrete)) {
            $name = trim($concrete);

            return $name === ''
                ? null
                : ['name' => $name, 'kind' => $this->kindForTypeName($name)];
        }

        if ($concrete instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($concrete);
                $static = $reflection->getStaticVariables();
                if (isset($static['concrete']) && is_string($static['concrete'])) {
                    return [
                        'name' => $static['concrete'],
                        'kind' => $this->kindForTypeName($static['concrete']),
                    ];
                }
                if (isset($static['abstract']) && is_string($static['abstract'])) {
                    return [
                        'name' => $static['abstract'],
                        'kind' => $this->kindForTypeName($static['abstract']),
                    ];
                }

                $returnType = $reflection->getReturnType();
                if ($returnType instanceof ReflectionNamedType && ! $returnType->isBuiltin()) {
                    return [
                        'name' => $returnType->getName(),
                        'kind' => $this->kindForTypeName($returnType->getName()),
                    ];
                }
            } catch (Throwable) {
                return null;
            }

            return ['name' => 'closure@'.$abstract, 'kind' => 'Closure'];
        }

        if (is_object($concrete)) {
            return ['name' => get_debug_type($concrete), 'kind' => 'Object'];
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
            return [null, 'Ignored', null];
        }

        if ($type instanceof ReflectionUnionType) {
            $resolved = $this->classNameFromUnionType($type);
            if ($resolved !== null) {
                return [$resolved, $this->kindForTypeName($resolved), null];
            }

            return [null, 'Ignored', null];
        }

        if (! $type instanceof ReflectionNamedType) {
            return [null, 'Ignored', null];
        }

        if ($type->isBuiltin()) {
            return [null, 'Ignored', null];
        }

        $name = $type->getName();

        return [$name, $this->kindForTypeName($name), null];
    }

    private function classNameFromUnionType(ReflectionUnionType $type): ?string
    {
        $candidate = null;

        foreach ($type->getTypes() as $namedType) {
            if (! $namedType instanceof ReflectionNamedType || $namedType->isBuiltin()) {
                continue;
            }

            if ($candidate !== null) {
                return null;
            }

            $candidate = $namedType->getName();
        }

        return $candidate;
    }

    private function kindForTypeName(string $name): string
    {
        if (interface_exists($name)) {
            return 'Interface';
        }

        if (class_exists($name)) {
            return 'Class';
        }

        return 'Alias';
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param  array<int, T>  $rows
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
     * @param  array<int, array{class: string}>  $classRows
     * @param  array<int, array{abstract: string, abstractKind: string, concrete: string, concreteKind: string, shared: bool, type: string}>  $bindingRows
     * @param  array<int, array{class: string, dependency: string, dependencyKind: string, type: string}>  $dependencyRows
     * @param  array<int, array{class: string, name: string, reason: string}>  $unresolvedRows
     */
    private function printCypher(ContainerGraphWriter $writer, array $classRows, array $bindingRows, array $dependencyRows, array $unresolvedRows): void
    {
        $this->line('');
        $this->line('Cypher templates:');
        foreach ($writer->cypherTemplates() as $label => $cypher) {
            $this->line('['.$label.']');
            $this->line($cypher);
            $this->line('');
        }

        $this->line('Sample params:');
        $this->line('- classes: '.json_encode(array_slice($classRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- bindings: '.json_encode(array_slice($bindingRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- dependencies: '.json_encode(array_slice($dependencyRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('- unresolved: '.json_encode(array_slice($unresolvedRows, 0, 2), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('');
    }
}
